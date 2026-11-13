<?php

namespace App\Services;

use App\Domain\Rules;
use App\Support\Fmt;
use App\Support\Sql;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Intelligence refresh cycle. Runs from the sweep (default every 30 min per workspace) or on demand.
 *   Level 1 — deterministic alerts, wins and the Priority Flag (no model).
 *   Insufficient-evidence cards when a signal exists but the sample is small.
 *   Level 2 — tactical recommendation (Growth+), memory recalled first.
 *   Level 3 — diagnostic, via Workers (Watchtower, with a connected source).
 *   Level 4 — strategic, weekly, over the memory's mental model (Watchtower).
 *   Memory — 7-day performance_after / outcome measurement.
 * The engine only writes insights and recommendations; it never changes operator data.
 */
final class Engine
{
    public const REC_PROMPT_VERSION = 'rec-v1';

    private static function loc(float|int|string|null $n): string
    {
        $s = number_format((float) $n, 3, '.', ',');

        return rtrim(rtrim($s, '0'), '.');
    }

    private static function pct(float $a, float $b): int
    {
        return (int) round((($a - $b) / $b) * 100);
    }

    /**
     * One live card per condition: an undismissed card with the same key is refreshed in place instead of duplicated;
     * a dismissed one stays dismissed for a day. Serialised per tenant + key with a named lock.
     */
    public static function upsertInsight(string $tenantId, array $i): bool
    {
        return Sql::locked("{$tenantId}:{$i['dedupe_key']}", function () use ($tenantId, $i) {
            $live = DB::table('insights')->where('tenant_id', $tenantId)->where('dedupe_key', $i['dedupe_key'])->whereNull('dismissed_at')->first(['id']);
            if ($live) {
                DB::table('insights')->where('id', $live->id)->update(['observation' => $i['observation'], 'evidence' => $i['evidence'] ?? null, 'severity' => $i['severity'], 'generated_at' => now()]);

                return false;
            }
            if (DB::table('insights')->where('tenant_id', $tenantId)->where('dedupe_key', $i['dedupe_key'])->where('dismissed_at', '>=', Fmt::ts(-86400))->exists()) {
                return false;
            }
            DB::table('insights')->insert([...$i, 'tenant_id' => $tenantId]);

            return true;
        });
    }

    /** Level 1 + insufficient evidence. Pure rules over live data. Returns the number of new cards. */
    public static function level1(object $t): int
    {
        $plan = $t->plan;
        $prefs = (array) (Fmt::json($t->notification_prefs) ?? []);
        $camps = DB::table('campaigns')->where('tenant_id', $t->id)->where('status', '<>', 'complete')->get();
        $stats = Metrics::campaignStats($t->id, $camps->pluck('id')->all());
        $avgConv = Metrics::workspaceConversion($t->id);
        $now = Rules::nowMs();
        $created = 0;
        $red = [];
        foreach ($camps as $c) {
            $s = $stats[$c->id];
            $thr = (int) $t->sla_threshold_minutes;
            $od = DB::table('leads')->where('tenant_id', $t->id)->where('campaign_id', $c->id)->where('status', 'not_responded')->where('received_at', '<', Fmt::ts(-$thr * 60))
                ->orderBy('received_at')->get(['full_name', 'received_at', 'assignee_id']);
            if ($n = $od->count()) {
                $red[$c->id][] = "{$n} overdue lead".($n > 1 ? 's' : '');
                $created += (int) self::upsertInsight($t->id, ['campaign_id' => $c->id, 'campaign_tag' => $c->name, 'type' => 'alert', 'severity' => 'red', 'category' => 'SLA', 'dedupe_key' => "sla:{$c->id}",
                    'observation' => "{$n} lead".($n > 1 ? 's are' : ' is')." overdue on {$c->name}.",
                    'evidence' => "{$od[0]->full_name} has waited ".Rules::formatDuration($now - Rules::ms($od[0]->received_at))." against a {$thr}-minute threshold. ".$od->whereNull('assignee_id')->count().' are unclaimed. Open the Lead Dossier and respond or notify the assignee.']);
            }
            $hooks = DB::table('deployments as d')->join('webhook_sources as h', 'h.deployment_id', '=', 'd.id')->where('d.tenant_id', $t->id)->where('d.campaign_id', $c->id)
                ->where('d.status', 'ready')->where('d.serving_state', 'active')->get(['d.*', 'h.last_received_at', 'h.stale_threshold_minutes']);
            foreach ($hooks as $d) {
                if (Rules::webhookState($d->last_received_at, (int) $d->stale_threshold_minutes) === 'offline') {
                    $red[$c->id][] = "{$d->name} webhook offline";
                    if (self::upsertInsight($t->id, ['campaign_id' => $c->id, 'campaign_tag' => $c->name, 'type' => 'alert', 'severity' => 'red', 'category' => 'SLA', 'dedupe_key' => "hook:{$d->id}",
                        'observation' => "The webhook on {$d->name} has gone silent.",
                        'evidence' => 'No submissions for '.Rules::formatDuration($now - Rules::ms($d->last_received_at)).'. If ads are still running, leads may be lost. Test the webhook from the Pages screen.'])) {
                        $created++;
                        if ($prefs['webhookOffline'] ?? true) {
                            Effects::notify($t->id, ['kind' => 'webhook_offline', 'description' => "{$d->name} webhook offline", 'link' => '/pages']);
                        }
                    }
                }
                $age = $d->deployed_at ? $now - Rules::ms($d->deployed_at) : null;
                if (Rules::hasFeature($plan, 'early_warning') && $age !== null && $age < (int) config('camplo.early_warning_hours') * Rules::HOUR && $age > 24 * Rules::HOUR && ! $d->early_warning_triggered) {
                    $visits = (int) DB::table('page_visits')->where('deployment_id', $d->id)->sum('visits');
                    $n = DB::table('leads')->where('deployment_id', $d->id)->count();
                    if ($visits >= 50 && ($avgConv === null ? $n / $visits < 0.01 : $n / $visits < $avgConv * 0.5)) {
                        DB::table('deployments')->where('id', $d->id)->update(['early_warning_triggered' => true, 'early_warning_triggered_at' => now()]);
                        $created += (int) self::upsertInsight($t->id, ['campaign_id' => $c->id, 'campaign_tag' => $c->name, 'type' => 'alert', 'severity' => 'amber', 'category' => 'Performance', 'dedupe_key' => "ew:{$d->id}",
                            'observation' => "72h watch: {$d->name} has {$n} leads from {$visits} visits since launch.",
                            'evidence' => 'That is '.number_format($n / $visits * 100, 1).'% conversion'.($avgConv !== null ? ' vs your '.number_format($avgConv * 100, 1).'% average' : '').'. Check the form and webhook before spending more budget.']);
                    }
                }
            }
            $cpl = Metrics::campaignCpl($c, $s);
            if (Rules::hasFeature($plan, 'budget') && $cpl !== null && $c->cpl_threshold !== null && $cpl > (float) $c->cpl_threshold) {
                if (self::upsertInsight($t->id, ['campaign_id' => $c->id, 'campaign_tag' => $c->name, 'type' => 'alert', 'severity' => 'amber', 'category' => 'Spend', 'dedupe_key' => "cpl:{$c->id}",
                    'observation' => "CPL on {$c->name} is above your ".self::loc($c->cpl_threshold).' threshold.',
                    'evidence' => 'Current CPL '.self::loc($cpl)." {$c->currency} (+".self::pct($cpl, (float) $c->cpl_threshold).'% over). Check webhook health and page conversion before increasing budget.'])) {
                    $created++;
                    if ($prefs['budget'] ?? true) {
                        Effects::notify($t->id, ['kind' => 'insight', 'description' => "CPL above threshold on {$c->name}", 'link' => "/campaigns/{$c->id}/insights"]);
                    }
                }
            }
            $h = Metrics::health($s);
            if ($h['signals']['acknowledgment'] === 'red') {
                $red[$c->id][] = 'acknowledgment rate below 70%';
            }
            $days = intdiv($now - Rules::ms($c->start_date.'T00:00:00Z'), Rules::DAY);
            $signal = $h['signals']['lead_volume'] !== 'green' || $h['signals']['acknowledgment'] !== 'green';
            $ie = fn () => DB::table('insights')->where('tenant_id', $t->id)->where('campaign_id', $c->id)->where('type', 'insufficient_evidence')->whereNull('dismissed_at');
            if (! Rules::hasSufficientEvidence($s['leadCount'], $days) && $signal) {
                if (! $ie()->exists()) {
                    DB::table('insights')->insert(['tenant_id' => $t->id, 'campaign_id' => $c->id, 'campaign_tag' => $c->name, 'type' => 'insufficient_evidence', 'severity' => 'blue', 'category' => 'Performance', 'dedupe_key' => "ie:{$c->id}",
                        'observation' => ($h['signals']['lead_volume'] !== 'green' ? 'Lead volume' : 'Acknowledgment rate')." on {$c->name} has softened — monitoring",
                        'evidence' => "The campaign has generated {$s['leadCount']} leads so far. The sample is too small to confidently recommend a change.",
                        'reassess_condition' => '100 additional leads OR 7 days — whichever comes first', 'reassess_at' => Fmt::ts(7 * 86400)]);
                    $created++;
                }
            } else {
                $ie()->update(['dismissed_at' => now()]);
            }
        }

        if (Rules::hasFeature($plan, 'win_signals') && $avgConv) {
            $since = Fmt::ts(-7 * 86400);
            $pages = DB::table('deployments as d')->leftJoin('campaigns as c', 'c.id', '=', 'd.campaign_id')->where('d.tenant_id', $t->id)->where('d.status', 'ready')->get(['d.*', 'c.name as cname']);
            $month = substr(gmdate('Y-m-d'), 0, 8);
            foreach ($pages as $d) {
                $visits = (int) DB::table('page_visits')->where('deployment_id', $d->id)->where('day', '>=', substr($since, 0, 10))->sum('visits');
                $n = DB::table('leads')->where('deployment_id', $d->id)->where('received_at', '>=', $since)->count();
                if ($visits >= 100 && $n / $visits > $avgConv * 2) {
                    $created += (int) self::upsertInsight($t->id, ['campaign_id' => $d->campaign_id, 'campaign_tag' => $d->cname, 'type' => 'win', 'severity' => 'green', 'category' => 'Performance', 'dedupe_key' => "winconv:{$d->id}:{$month}",
                        'observation' => "{$d->name} is converting at ".number_format($n / $visits / $avgConv, 1).'× your account average.',
                        'evidence' => number_format($n / $visits * 100, 1).'% conversion vs '.number_format($avgConv * 100, 1).'% average across '.number_format($visits).' visits in the last 7 days.']);
                }
            }
        }

        foreach ($red as $cid => $reasons) {
            if (count($reasons) < 2) {
                continue;
            }
            $c = $camps->firstWhere('id', $cid);
            if (self::upsertInsight($t->id, ['campaign_id' => $cid, 'campaign_tag' => $c->name, 'type' => 'priority_flag', 'severity' => 'red', 'category' => 'SLA', 'dedupe_key' => "pf:{$cid}",
                'observation' => "{$c->name} is failing at ".count($reasons).' handoffs at once — this is systemic, not one slow rep.',
                'evidence' => implode(', ', $reasons).'. Fix the pipeline before adding budget.'])) {
                $created++;
                Effects::notify($t->id, ['kind' => 'insight', 'description' => "Priority Flag raised on {$c->name}", 'link' => "/campaigns/{$cid}/insights"]);
            }
        }
        if ($created) {
            Effects::emit($t->id, 'workspace:insights');
        }

        return $created;
    }

    private static function recPrompt(int $level): string
    {
        $kind = match ($level) {
            2 => 'TACTICAL (what should change)', 3 => 'DIAGNOSTIC (why it is happening, root cause across sources)', default => 'STRATEGIC (what to fundamentally change, cross-campaign)'
        };

        return "You are Camplo's {$kind} recommendation engine (".self::REC_PROMPT_VERSION.').
Work through, in order: 1 business objective, 2 campaign objective, 3 funnel stage, 4 performance vs baseline, 5 segment comparison vs workspace,
6 anomalies (what changed, when), 7 campaign memory (what was tried and the measured outcome), 8 possible causes ranked by evidence,
9 evidence for each, 10 candidate actions, 11 expected impact, 12 risks, 13 output.
Hard rules: use only numbers present in the input; never repeat an approach memory shows failed; state data gaps honestly;
if evidence does not support a confident recommendation, set confidence to "low".
Return ONLY JSON: {"action_text","why","evidence":[{"metric","change","period"}],"diagnosis","expected_outcome","risk","confidence":"low|medium|high","next_step","why_now","memory_references":[ids],"apply_action":{"type","platform","adjustment"}|null}';
    }

    /** Validate the model's recommendation (the zod schema of the Node engine). */
    public static function parseRec(mixed $r): ?array
    {
        if (! is_array($r)) {
            return null;
        }
        $str = fn ($k) => is_string($r[$k] ?? null);
        foreach (['action_text', 'why', 'expected_outcome', 'risk', 'next_step', 'why_now'] as $k) {
            if (! $str($k)) {
                return null;
            }
        }
        $len = mb_strlen($r['action_text']);
        if ($len < 5 || $len > 500 || ! in_array($r['confidence'] ?? null, ['low', 'medium', 'high'], true)) {
            return null;
        }
        $ev = $r['evidence'] ?? null;
        if (! is_array($ev) || ! $ev) {
            return null;
        }
        foreach ($ev as $e) {
            if (! is_array($e) || ! is_string($e['metric'] ?? null) || ! is_string($e['change'] ?? null) || ! is_string($e['period'] ?? null)) {
                return null;
            }
        }
        if (isset($r['diagnosis']) && ! is_string($r['diagnosis'])) {
            return null;
        }
        $refs = $r['memory_references'] ?? [];
        if (! is_array($refs) || array_filter($refs, fn ($x) => ! is_string($x))) {
            return null;
        }
        $apply = $r['apply_action'] ?? null;
        if ($apply !== null && (! is_array($apply) || ! is_string($apply['type'] ?? null) || ! is_string($apply['platform'] ?? null))) {
            return null;
        }

        return [...$r, 'memory_references' => array_values($refs), 'diagnosis' => $r['diagnosis'] ?? null, 'apply_action' => $apply];
    }

    private static function recommend(object $t, int $level, ?string $campaignId, array $payload, array $memoryIds): ?string
    {
        $res = Ai::complete($t->id, $t->plan, ['workload' => [2 => 'standard', 3 => 'deep'][$level] ?? 'strategic', 'taskType' => 'recommendation', 'json' => true, 'maxTokens' => 2000,
            'system' => self::recPrompt($level), 'messages' => [['role' => 'user', 'content' => mb_substr(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 50000)]]]);
        if (! $res['completion'] || ! ($r = self::parseRec(Ai::parseJson($res['completion']['text'])))) {
            return null;
        }
        if ($level >= 3 && $r['confidence'] === 'low') {
            return null;
        }
        $refs = array_values(array_intersect($r['memory_references'], $memoryIds));
        $a = $r['apply_action'];
        $id = Sql::insertId('campaign_recommendations', [
            'tenant_id' => $t->id, 'campaign_id' => $campaignId, 'level' => $level, 'action_text' => $r['action_text'], 'why' => $r['why'], 'evidence' => json_encode($r['evidence']),
            'diagnosis' => $r['diagnosis'], 'expected_outcome' => $r['expected_outcome'], 'risk' => $r['risk'], 'confidence' => $r['confidence'], 'next_step' => $r['next_step'],
            'why_now' => $r['why_now'], 'memory_references' => json_encode($refs),
            // Dormant in v1 — present so v2 Autopilot needs no data-model change.
            'apply_action' => $a ? json_encode(array_filter(['type' => $a['type'], 'platform' => $a['platform'], 'adjustment' => $a['adjustment'] ?? null, 'status' => 'pending_v2'], fn ($v) => $v !== null)) : null,
        ]);
        Effects::remember($t->id, ['type' => 'recommendation', 'campaignId' => $campaignId, 'entityId' => $id, 'content' => "Camplo recommended (L{$level}, {$r['confidence']}): {$r['action_text']}. Why: {$r['why']}"]);

        return $id;
    }

    public static function levels2to4(object $t): int
    {
        $plan = $t->plan;
        if (! Rules::recommendationLevelAllowed($plan, 2) || ! Ai::targetsFor($t->id, 'standard')) {
            return 0;
        }
        $made = 0;
        $now = Rules::nowMs();
        $camps = DB::table('campaigns')->where('tenant_id', $t->id)->where('status', '<>', 'complete')->get();
        $stats = Metrics::campaignStats($t->id, $camps->pluck('id')->all());
        $sources = DB::table('integrations')->where('tenant_id', $t->id)->where('status', 'connected')->pluck('provider')->all();
        foreach ($camps as $c) {
            $s = $stats[$c->id];
            $days = intdiv($now - Rules::ms($c->start_date.'T00:00:00Z'), Rules::DAY);
            if (! Rules::hasSufficientEvidence($s['leadCount'], $days)) {
                continue;
            }
            if (DB::table('campaign_recommendations')->where('campaign_id', $c->id)->where('surfaced_at', '>=', Fmt::ts(-4 * 3600))->exists()) {
                continue;
            }
            $h = Metrics::health($s);
            $memories = Memory::recall($t->id, "What changed recently on {$c->name} and what were the outcomes?", $c->id);
            $cpl = Metrics::campaignCpl($c, $s);
            $base = [
                'campaign' => ['id' => $c->id, 'name' => $c->name, 'objective' => 'qualified leads', 'description' => $c->description, 'days_active' => $days, 'currency' => $c->currency],
                'performance' => ['leads_7d' => $s['leads7d'], 'prior_weekly_avg' => $s['avgWeekly'], 'ack_rate_7d' => $s['leads7d'] ? $s['responded7d'] / $s['leads7d'] : null,
                    'avg_speed_to_lead' => Rules::formatDuration($s['avgResponseMs']), 'cpl' => $cpl, 'cpl_threshold' => Fmt::num($c->cpl_threshold), 'health' => $h],
                'memory' => array_slice($memories, 0, 10), 'data_sources' => ['camplo_native', ...$sources],
            ];
            $ids = array_column($memories, 'id');
            if ($h['status'] !== 'healthy' && Rules::recommendationLevelAllowed($plan, 3) && count($sources) >= 1) {
                $workers = array_map(fn ($cap) => Workers::run($plan, Workers::contract(['tenantId' => $t->id, 'capability' => $cap, 'objective' => "Find the root cause of {$c->name}'s {$h['status']} health", 'campaignId' => $c->id, 'budget' => 'high'])),
                    ['investigation', 'campaign_analysis']);
                $made += (int) (bool) self::recommend($t, 3, $c->id, [...$base, 'investigation' => array_column($workers, 'output')], $ids);
            } elseif ($h['status'] !== 'healthy' || ($cpl !== null && $c->cpl_threshold !== null && $cpl > (float) $c->cpl_threshold)) {
                $made += (int) (bool) self::recommend($t, 2, $c->id, $base, $ids);
            }
        }
        if (Rules::recommendationLevelAllowed($plan, 4) && $camps->count() >= 2
            && ! DB::table('campaign_recommendations')->where('tenant_id', $t->id)->where('level', 4)->where('surfaced_at', '>=', Fmt::ts(-7 * 86400))->exists()) {
            $model = Memory::reflect($t->id, 'What patterns across all campaigns should change how this workspace operates?');
            $all = $camps->map(fn ($c) => ['name' => $c->name, ...$stats[$c->id], 'cpl' => Metrics::campaignCpl($c, $stats[$c->id])])->all();
            $made += (int) (bool) self::recommend($t, 4, null, ['campaigns' => $all, 'mental_model' => $model, 'window' => '90 days'], []);
        }
        if ($made) {
            Effects::emit($t->id, 'workspace:insights');
        }

        return $made;
    }

    /** Capture performance 7 days after each change and each actioned recommendation. */
    public static function measureMemory(?string $tenantId = null): void
    {
        $cutoff = Fmt::ts(-7 * 86400);
        $q = DB::table('campaign_changes')->whereNull('performance_after')->where('changed_at', '<', $cutoff);
        if ($tenantId) {
            $q->where('tenant_id', $tenantId);
        }
        foreach ($q->limit(50)->get() as $ch) {
            $after = CampaignService::perfSnapshot($ch->tenant_id, $ch->campaign_id);
            DB::table('campaign_changes')->where('id', $ch->id)->update(['performance_after' => json_encode($after)]);
            Effects::remember($ch->tenant_id, ['type' => 'experiment', 'campaignId' => $ch->campaign_id, 'entityId' => $ch->id,
                'content' => "Outcome 7 days after {$ch->change_type} change \"{$ch->change_description}\": before ".json_encode(Fmt::json($ch->performance_before), JSON_UNESCAPED_SLASHES).', after '.json_encode($after, JSON_UNESCAPED_SLASHES).'.']);
        }
        $q = DB::table('campaign_recommendations')->where('outcome_measured', false)->where('status', '<>', 'surfaced')->where('actioned_at', '<', $cutoff);
        if ($tenantId) {
            $q->where('tenant_id', $tenantId);
        }
        foreach ($q->limit(50)->get() as $r) {
            $after = $r->campaign_id ? CampaignService::perfSnapshot($r->tenant_id, $r->campaign_id) : null;
            DB::table('campaign_recommendations')->where('id', $r->id)->update(['outcome_measured' => true, 'measured_at' => now(), 'outcome_data' => $after === null ? null : json_encode($after)]);
        }
    }

    /** Event trigger (SLA breach, webhook silence, critical health, lead batch): a forced refresh after the response, at most every 30 s. */
    public static function trigger(string $tenantId, string $reason): void
    {
        if (Cache::add("engine:trigger:{$tenantId}", $reason, 30)) {
            Jobs::defer(fn () => self::refresh($tenantId, true));
        }
    }

    /** One refresh cycle for a workspace. `force` bypasses the schedule (manual refresh, event triggers). */
    public static function refresh(string $tenantId, bool $force = false): array
    {
        $t = DB::table('tenants')->where('id', $tenantId)->first();
        if (! $t || $t->status !== 'active') {
            return ['level1' => 0, 'recommendations' => 0];
        }
        $cfg = DB::table('ai_provider_configs')->where('tenant_id', $tenantId)->first();
        $interval = (int) ($cfg->refresh_interval_minutes ?? 30) * 60_000;
        $last = Cache::get("engine:last:{$tenantId}") ?? ($cfg && $cfg->last_refresh_at ? Rules::ms($cfg->last_refresh_at) : 0);
        if (! $force && Rules::nowMs() - $last < $interval) {
            return ['level1' => 0, 'recommendations' => 0, 'skipped' => true];
        }
        Cache::put("engine:last:{$tenantId}", Rules::nowMs(), 86400);
        $l1 = self::level1($t);
        $recs = 0;
        try {
            $recs = self::levels2to4($t);
        } catch (Throwable $e) {
            Log::warning('[engine] L2-4 failed: '.$e->getMessage());
        }
        if ($cfg) {
            DB::table('ai_provider_configs')->where('id', $cfg->id)->update(['last_refresh_at' => now()]);
        }

        return ['level1' => $l1, 'recommendations' => $recs];
    }
}
