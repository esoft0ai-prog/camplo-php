<?php

namespace App\Services;

use App\Domain\Rules;
use App\Support\Ctx;
use App\Support\Fail;
use App\Support\Fmt;
use App\Support\Sql;
use App\Support\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class CampaignService
{
    private static function money(mixed $v): int|float|null
    {
        return $v === null ? null : $v + 0;
    }

    public static function serialize(object $c, array $s, array $members, string $plan): array
    {
        $h = Metrics::health($s);
        $pulse = Rules::hasFeature($plan, 'health_pulse');

        return [
            'id' => $c->id, 'name' => $c->name, 'description' => $c->description, 'status' => $c->status,
            'health' => $pulse ? $h['status'] : null, 'healthSignals' => $pulse ? $h['signals'] : null,
            'ownerId' => $c->owner_id, 'startDate' => $c->start_date, 'budget' => self::money($c->budget), 'currency' => $c->currency,
            'dailySpend' => self::money($c->daily_spend), 'cplThreshold' => self::money($c->cpl_threshold),
            'cpl' => Rules::hasFeature($plan, 'budget') ? Metrics::campaignCpl($c, $s) : null, 'pinned' => (bool) $c->pinned, 'retrospectiveReady' => (bool) $c->retrospective_ready,
            'leadCount' => $s['leadCount'], 'respondedCount' => $s['respondedCount'], 'notRespondedCount' => $s['notRespondedCount'], 'avgResponseMs' => $s['avgResponseMs'],
            'pageCount' => $s['pageCount'], 'notesCount' => $s['notesCount'],
            'daysActive' => max(0, intdiv(Rules::nowMs() - Rules::ms($c->start_date.'T00:00:00Z'), Rules::DAY)),
            'members' => $members, 'createdAt' => Fmt::iso($c->created_at), 'updatedAt' => Fmt::iso($c->updated_at), 'completedAt' => Fmt::iso($c->completed_at),
            'shareLinkActive' => (bool) $c->share_link_active,
        ];
    }

    private static function membersOf(string $tenantId, array $ids): array
    {
        $map = [];
        if (! $ids) {
            return $map;
        }
        foreach (DB::table('campaign_members')->where('tenant_id', $tenantId)->whereIn('campaign_id', $ids)->orderBy('added_at')->get() as $r) {
            $map[$r->campaign_id][] = $r->user_id;
        }

        return $map;
    }

    public static function list(Ctx $ctx): array
    {
        $rows = DB::table('campaigns')->where('tenant_id', $ctx->tenantId)->orderByDesc('pinned')->orderByDesc('updated_at')->get();
        $ids = $rows->pluck('id')->all();
        $stats = Metrics::campaignStats($ctx->tenantId, $ids);
        $mem = self::membersOf($ctx->tenantId, $ids);

        return $rows->map(fn ($c) => self::serialize($c, $stats[$c->id], $mem[$c->id] ?? [], $ctx->plan))->all();
    }

    public static function load(Ctx $ctx, string $id): object
    {
        $c = Str::isUuid($id) ? DB::table('campaigns')->where('tenant_id', $ctx->tenantId)->where('id', $id)->first() : null;
        if (! $c) {
            throw Fail::notFound('Campaign not found.');
        }

        return $c;
    }

    public static function get(Ctx $ctx, string $id): array
    {
        $c = self::load($ctx, $id);

        return self::serialize($c, Metrics::campaignStats($ctx->tenantId, [$id])[$id], self::membersOf($ctx->tenantId, [$id])[$id] ?? [], $ctx->plan);
    }

    public static function create(Ctx $ctx, array $in): array
    {
        $ctx->assertPerm('campaigns.manage');
        $n = DB::table('campaigns')->where('tenant_id', $ctx->tenantId)->where('status', '<>', 'complete')->count();
        $lim = Platform::planLimits($ctx->plan)['campaigns'];
        if ($lim >= 0 && $n >= $lim) {
            throw Fail::planLimit("Your plan includes {$lim} active campaigns. Upgrade to add more.", $ctx->plan === 'starter' ? 'growth' : 'watchtower');
        }
        $budget = $in['budget'] ?? null;
        $id = Sql::insertId('campaigns', [
            'tenant_id' => $ctx->tenantId, 'name' => trim($in['name']), 'description' => $in['description'] ?? null, 'owner_id' => $ctx->user->id,
            'start_date' => $in['startDate'] ?? gmdate('Y-m-d'), 'currency' => 'USD', 'budget' => $budget,
            'cpl_threshold' => $budget === null ? null : ($in['cplThreshold'] ?? null), 'daily_spend' => $in['dailySpend'] ?? null,
        ]);
        DB::table('campaign_members')->insert(['tenant_id' => $ctx->tenantId, 'campaign_id' => $id, 'user_id' => $ctx->user->id]);
        $c = DB::table('campaigns')->where('id', $id)->first();
        Effects::logCampaign($ctx->tenantId, $id, $ctx->user->id, "Campaign created by {$ctx->user->name}");
        Effects::logWorkspace($ctx->tenantId, $ctx->user->id, "Campaign \"{$c->name}\" created");
        Effects::remember($ctx->tenantId, ['type' => 'campaign_event', 'campaignId' => $id, 'content' => "Campaign \"{$c->name}\" created on {$c->start_date}."]);

        return self::get($ctx, $id);
    }

    /** Snapshot used for campaign_changes.performance_before/after. */
    public static function perfSnapshot(string $tenantId, string $campaignId): array
    {
        $s = Metrics::campaignStats($tenantId, [$campaignId])[$campaignId];
        $c = DB::table('campaigns')->where('id', $campaignId)->first();
        $visits = (int) DB::table('page_visits')->join('deployments', 'deployments.id', '=', 'page_visits.deployment_id')
            ->where('page_visits.tenant_id', $tenantId)->where('deployments.campaign_id', $campaignId)->where('page_visits.day', '>=', gmdate('Y-m-d', time() - 7 * 86400))->sum('page_visits.visits');

        return [
            'lead_count_7d' => $s['leads7d'], 'avg_response_ms' => $s['avgResponseMs'], 'acknowledgment_rate' => $s['leads7d'] ? $s['responded7d'] / $s['leads7d'] : null,
            'cpl' => $c ? Metrics::campaignCpl($c, $s) : null, 'conversion_rate' => $visits ? $s['leads7d'] / $visits : null,
        ];
    }

    public static function update(Ctx $ctx, string $id, array $p): array
    {
        $ctx->assertPerm('campaigns.manage');
        $c = self::load($ctx, $id);
        $has = fn ($k) => array_key_exists($k, $p);
        if ($c->status === 'complete' && ($has('dailySpend') || $has('budget'))) {
            throw Fail::bad('Completed campaigns cannot be changed.');
        }
        $set = ['updated_at' => now()];
        $logs = [];
        $show = fn ($v) => $v === null ? '—' : (string) ($v + 0);
        if ($has('name') && trim($p['name']) !== $c->name) {
            $set['name'] = trim($p['name']);
            $logs[] = "Renamed to \"{$set['name']}\"";
        }
        if ($has('description')) {
            $set['description'] = $p['description'];
            $logs[] = 'Campaign brief updated';
        }
        if ($has('startDate')) {
            $set['start_date'] = $p['startDate'];
        }
        if ($has('status') && $p['status'] !== null) {
            if ($c->status === 'complete') {
                throw Fail::bad('Completed campaigns cannot be paused or resumed.');
            }
            if ($p['status'] === 'paused' && $c->status !== 'paused') {
                $set['status'] = 'paused';
                $logs[] = 'Campaign paused';
            }
            if ($p['status'] === 'active' && $c->status === 'paused') {
                $set['status'] = 'active';
                $logs[] = 'Campaign resumed';
            }
        }
        if ($has('dailySpend')) {
            $set['daily_spend'] = $p['dailySpend'];
            $logs[] = 'Daily spend set to '.$show($p['dailySpend']);
        }
        if ($has('budget')) {
            $set['budget'] = $p['budget'];
            $logs[] = 'Budget set to '.$show($p['budget']);
        }
        if ($has('cplThreshold')) {
            $set['cpl_threshold'] = $p['cplThreshold'];
            $logs[] = 'CPL threshold set to '.$show($p['cplThreshold']);
        }
        $change = $p['change'] ?? null;
        if ($has('dailySpend') || $has('budget') || $change) {
            $before = self::perfSnapshot($ctx->tenantId, $id);
            $type = $change['type'] ?? 'budget';
            $desc = $change['description'] ?? implode('; ', array_values(array_filter($logs, fn ($l) => preg_match('/spend|Budget/', $l))));
            $chId = Sql::insertId('campaign_changes', ['tenant_id' => $ctx->tenantId, 'campaign_id' => $id, 'change_type' => $type, 'change_description' => $desc, 'changed_by' => $ctx->user->id, 'performance_before' => json_encode($before)]);
            Effects::remember($ctx->tenantId, ['type' => 'experiment', 'campaignId' => $id, 'entityId' => $chId, 'content' => "{$type} change on \"{$c->name}\": {$desc}. Before: ".json_encode($before)]);
            if ($change) {
                $logs[] = "Logged {$type} change: {$change['description']}";
            }
        }
        DB::table('campaigns')->where('tenant_id', $ctx->tenantId)->where('id', $id)->update($set);
        foreach ($logs as $l) {
            Effects::logCampaign($ctx->tenantId, $id, $ctx->user->id, "{$l} by {$ctx->user->name}");
        }
        Effects::emit($ctx->tenantId, "campaign:{$id}:health");

        return self::get($ctx, $id);
    }

    public static function delete(Ctx $ctx, string $id): array
    {
        $ctx->assertRole('owner');
        $c = self::load($ctx, $id);
        DB::table('campaigns')->where('tenant_id', $ctx->tenantId)->where('id', $id)->delete();
        Effects::logWorkspace($ctx->tenantId, $ctx->user->id, "Campaign \"{$c->name}\" deleted");

        return ['ok' => true];
    }

    public static function setPinned(Ctx $ctx, string $id, bool $pinned): array
    {
        $ctx->assertPerm('campaigns.manage');
        self::load($ctx, $id);
        if ($pinned && DB::table('campaigns')->where('tenant_id', $ctx->tenantId)->where('pinned', true)->count() >= 3) {
            throw Fail::conflict('You can pin up to 3 campaigns. Unpin one to pin this one.', 'pin_limit_reached');
        }
        DB::table('campaigns')->where('tenant_id', $ctx->tenantId)->where('id', $id)->update(['pinned' => $pinned]);

        return ['ok' => true, 'pinned' => $pinned];
    }

    public static function health(Ctx $ctx, string $id): array
    {
        self::load($ctx, $id);
        $ctx->assertFeature('health_pulse');

        return Metrics::health(Metrics::campaignStats($ctx->tenantId, [$id])[$id]);
    }

    public static function speedToLead(Ctx $ctx, string $id): array
    {
        self::load($ctx, $id);
        $s = Metrics::campaignStats($ctx->tenantId, [$id])[$id];

        return ['avgResponseMs' => $s['avgResponseMs'], 'formatted' => Rules::formatDuration($s['avgResponseMs']), 'color' => Rules::speedColor($s['avgResponseMs'])];
    }

    public static function cplInfo(Ctx $ctx, string $id): array
    {
        $c = self::load($ctx, $id);
        $ctx->assertFeature('budget');
        $s = Metrics::campaignStats($ctx->tenantId, [$id])[$id];
        $v = Metrics::campaignCpl($c, $s);
        $thr = self::money($c->cpl_threshold);

        return ['cpl' => $v, 'threshold' => $thr, 'overThreshold' => $v !== null && $thr !== null && $v > $thr, 'leadCount' => $s['leadCount']];
    }

    /** Keyset-paginated campaign log; the cursor is the created_at of the last row. */
    public static function logs(Ctx $ctx, string $id, ?string $from, ?string $to, ?string $cursor, int $limit): array
    {
        self::load($ctx, $id);
        $ctx->assertFeature('campaign_logs');
        try {
            $fromD = $from ? new \DateTimeImmutable($from) : new \DateTimeImmutable('-7 days');
            $toD = $to ? (new \DateTimeImmutable($to))->modify(strlen($to) <= 10 ? '+1 day -1 millisecond' : '+0 seconds') : new \DateTimeImmutable('now');
            $cur = $cursor ? new \DateTimeImmutable($cursor) : null;
        } catch (Throwable) {
            throw Fail::bad('Input validation failed');
        }
        $q = DB::table('campaign_logs')->leftJoin('users', 'users.id', '=', 'campaign_logs.actor_id')
            ->where('campaign_logs.tenant_id', $ctx->tenantId)->where('campaign_logs.campaign_id', $id)
            ->where('campaign_logs.created_at', '>=', $fromD->format('Y-m-d H:i:s.uP'))->where('campaign_logs.created_at', '<=', $toD->format('Y-m-d H:i:s.uP'));
        if ($cur) {
            $q->where('campaign_logs.created_at', '<', $cur->format('Y-m-d H:i:s.uP'));
        }
        $rows = $q->orderByDesc('campaign_logs.created_at')->limit($limit + 1)
            ->get(['campaign_logs.id', 'campaign_logs.actor_id', 'users.name', 'campaign_logs.description', 'campaign_logs.created_at']);
        $page = $rows->slice(0, $limit)->values();

        return [
            'logs' => $page->map(fn ($r) => ['id' => $r->id, 'actorId' => $r->actor_id, 'actorName' => $r->name ?? 'Camplo', 'description' => $r->description, 'createdAt' => Fmt::iso($r->created_at)])->all(),
            'has_more' => $rows->count() > $limit, 'next_cursor' => $rows->count() > $limit ? Fmt::iso($page->last()->created_at) : null,
        ];
    }

    // ------------------------------------------------------------- completion + retrospective

    public static function complete(Ctx $ctx, string $id): array
    {
        $ctx->assertPerm('campaigns.manage');
        $c = self::load($ctx, $id);
        if ($c->status === 'complete') {
            throw Fail::conflict('This campaign is already complete.');
        }
        DB::table('campaigns')->where('id', $id)->update(['status' => 'complete', 'completed_at' => now(), 'pinned' => false, 'updated_at' => now()]);
        DB::table('campaign_retrospectives')->insertOrIgnore(['tenant_id' => $ctx->tenantId, 'campaign_id' => $id]);
        Effects::logCampaign($ctx->tenantId, $id, $ctx->user->id, "Campaign marked complete by {$ctx->user->name}");
        Effects::remember($ctx->tenantId, ['type' => 'campaign_event', 'campaignId' => $id, 'content' => "Campaign \"{$c->name}\" marked complete."]);
        Effects::fireOutbound($ctx->tenantId, 'campaign.completed', ['campaign_id' => $id, 'name' => $c->name]);
        $tid = $ctx->tenantId;
        Jobs::defer(fn () => self::generateRetrospective($tid, $id));

        return ['ok' => true];
    }

    private static function pageConversion(string $pageId): ?float
    {
        $v = (int) DB::table('page_visits')->where('deployment_id', $pageId)->sum('visits');
        $l = DB::table('leads')->where('deployment_id', $pageId)->count();

        return $v ? $l / $v : null;
    }

    public static function generateRetrospective(string $tenantId, string $campaignId): void
    {
        $c = DB::table('campaigns')->where('tenant_id', $tenantId)->where('id', $campaignId)->first();
        $t = DB::table('tenants')->where('id', $tenantId)->first();
        if (! $c || ! $t) {
            return;
        }
        $s = Metrics::campaignStats($tenantId, [$campaignId])[$campaignId];
        $best = $worst = null;
        foreach (DB::table('deployments')->where('tenant_id', $tenantId)->where('campaign_id', $campaignId)->get(['id']) as $p) {
            $rate = self::pageConversion($p->id);
            if ($rate === null) {
                continue;
            }
            if (! $best || $rate > $best['rate']) {
                $best = ['id' => $p->id, 'rate' => $rate];
            }
            if (! $worst || $rate < $worst['rate']) {
                $worst = ['id' => $p->id, 'rate' => $rate];
            }
        }
        $ack = $s['leadCount'] ? $s['respondedCount'] / $s['leadCount'] : null;
        $cpl = Metrics::campaignCpl($c, $s);
        $end = $c->completed_at ? substr(Fmt::iso($c->completed_at), 0, 10) : gmdate('Y-m-d');
        $facts = [
            "Campaign \"{$c->name}\" ran {$c->start_date} to {$end}.",
            "{$s['leadCount']} leads; {$s['respondedCount']} responded (".($ack === null ? 'n/a' : round($ack * 100).'%').').',
            'Average speed-to-lead '.Rules::formatDuration($s['avgResponseMs']).' against a 5-minute target.',
            $cpl !== null ? "Cost per lead \${$cpl}." : 'No budget tracked.',
        ];
        $observation = Ai::text($tenantId, $t->plan, [
            'workload' => 'strategic', 'taskType' => 'retrospective', 'maxTokens' => 1200, 'timeout' => 300,
            'system' => 'You are Camplo, a campaign decision engine. Write one paragraph (4-6 sentences) observing what this finished campaign\'s data suggests for the next campaign. Be specific, cite numbers, never invent data, no bullet points.',
            'messages' => [['role' => 'user', 'content' => implode("\n", $facts)]],
        ]);
        if (! $observation) {
            $avg = $s['avgResponseMs'];
            $speed = $avg === null ? 'Response speed could not be measured.' : ($avg < 5 * Rules::MIN
                ? 'The team averaged '.Rules::formatDuration($avg).' to respond — inside the five-minute window where contact rates are highest.'
                : 'The team averaged '.Rules::formatDuration($avg).' to respond, outside the five-minute window; staffing the busiest arrival hours is the clearest lever for the next campaign.');
            $observation = "{$facts[1]} {$speed} ".($ack !== null && $ack < 0.9 ? 'Roughly one in ten leads was never acknowledged — treat that as lost revenue to recover next time.' : 'Acknowledgment discipline held throughout.').' Judge the next campaign on cost per qualified opportunity, not cost per lead.';
        }
        DB::table('campaign_retrospectives')->where('campaign_id', $campaignId)->update([
            'generated' => true, 'generated_at' => now(), 'total_leads' => $s['leadCount'], 'responded_count' => $s['respondedCount'], 'avg_response_time_ms' => $s['avgResponseMs'],
            'acknowledgment_rate' => $ack === null ? null : round($ack, 4), 'cpl' => $cpl, 'best_page_id' => $best['id'] ?? null,
            'worst_page_id' => $worst && $worst['id'] !== ($best['id'] ?? null) ? $worst['id'] : null, 'ai_observation' => $observation,
        ]);
        DB::table('campaigns')->where('id', $campaignId)->update(['retrospective_ready' => true]);
        $r = DB::table('campaign_retrospectives')->where('campaign_id', $campaignId)->first();
        try {
            self::storeRetroPdf($t, $c, $r);
        } catch (Throwable $e) {
            Log::error('[retro] pdf '.$e->getMessage());
        }
        Effects::emit($tenantId, 'workspace:insights', ['campaignId' => $campaignId]);
    }

    private static function loadRetro(Ctx $ctx, string $id): array
    {
        $c = self::load($ctx, $id);
        $ctx->assertFeature('retrospective');
        if ($c->status !== 'complete') {
            throw Fail::notFound('Retrospective not yet available.');
        }

        return [$c, DB::table('campaign_retrospectives')->where('tenant_id', $ctx->tenantId)->where('campaign_id', $id)->first()];
    }

    public static function retrospectiveStatus(Ctx $ctx, string $id): array
    {
        [, $r] = self::loadRetro($ctx, $id);

        return ['generated' => (bool) ($r->generated ?? false)];
    }

    public static function retrospective(Ctx $ctx, string $id): array
    {
        [$c, $r] = self::loadRetro($ctx, $id);
        if (! ($r->generated ?? false)) {
            return ['generated' => false];
        }

        return self::retroView($c, $r);
    }

    private static function retroView(object $c, object $r): array
    {
        $page = function (?string $pid) {
            if (! $pid) {
                return null;
            }
            $p = DB::table('deployments')->where('id', $pid)->first(['name']);

            return $p ? ['name' => $p->name, 'conversionRate' => self::pageConversion($pid)] : null;
        };
        $endMs = Rules::ms($c->completed_at) ?? Rules::nowMs();

        return [
            'generated' => true, 'campaignName' => $c->name, 'startDate' => $c->start_date, 'endDate' => gmdate('Y-m-d', intdiv($endMs, 1000)),
            'daysActive' => max(1, (int) round(($endMs - Rules::ms($c->start_date.'T00:00:00Z')) / Rules::DAY)),
            'totalLeads' => $r->total_leads === null ? null : (int) $r->total_leads, 'respondedCount' => $r->responded_count === null ? null : (int) $r->responded_count,
            'avgResponseTimeMs' => $r->avg_response_time_ms === null ? null : (int) $r->avg_response_time_ms,
            'acknowledgmentRate' => Fmt::num($r->acknowledgment_rate), 'cpl' => Fmt::num($r->cpl), 'currency' => $c->currency,
            'bestPage' => $page($r->best_page_id), 'worstPage' => $page($r->worst_page_id), 'aiObservation' => $r->ai_observation, 'generatedAt' => Fmt::iso($r->generated_at),
        ];
    }

    public static function pdfName(string $campaignName): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($campaignName)), '').'-retrospective.pdf';
    }

    /** Served from storage (pdf_url); rendered and stored on first request if generation couldn't. */
    public static function retrospectivePdf(Ctx $ctx, string $id): array
    {
        [$c, $r] = self::loadRetro($ctx, $id);
        if (! ($r->generated ?? false)) {
            throw Fail::notFound('Retrospective is still generating.');
        }
        $stored = $r->pdf_url ? Storage::get($r->pdf_url) : null;

        return ['filename' => self::pdfName($c->name), 'data' => $stored['data'] ?? self::storeRetroPdf($ctx->tenant, $c, $r)];
    }

    private static function storeRetroPdf(object $t, object $c, object $r): string
    {
        $data = RetroPdf::render($t, self::retroView($c, $r));
        $key = "retros/{$t->id}/{$c->id}.pdf";
        Storage::put($key, $data, 'application/pdf');
        DB::table('campaign_retrospectives')->where('id', $r->id)->update(['pdf_url' => $key]);

        return $data;
    }

    // ------------------------------------------------------------- share links + public view

    public static function getShareLink(Ctx $ctx, string $id): array
    {
        $c = self::load($ctx, $id);
        $ctx->assertFeature('client_view');
        if (! $c->share_token || ! $c->share_link_active) {
            throw Fail::notFound('No share link yet.');
        }

        return ['shareUrl' => config('camplo.app_url')."/#/share/{$c->share_token}"];
    }

    public static function createShareLink(Ctx $ctx, string $id): array
    {
        $ctx->assertPerm('campaigns.share');
        self::load($ctx, $id);
        $ctx->assertFeature('client_view');
        $token = (string) Str::uuid();
        DB::table('campaigns')->where('tenant_id', $ctx->tenantId)->where('id', $id)->update(['share_token' => $token, 'share_link_active' => true]);
        Effects::logCampaign($ctx->tenantId, $id, $ctx->user->id, "Client share link generated by {$ctx->user->name}");

        return ['shareUrl' => config('camplo.app_url')."/#/share/{$token}"];
    }

    public static function revokeShareLink(Ctx $ctx, string $id): array
    {
        $ctx->assertPerm('campaigns.share');
        self::load($ctx, $id);
        DB::table('campaigns')->where('tenant_id', $ctx->tenantId)->where('id', $id)->update(['share_token' => null, 'share_link_active' => false]);
        Effects::logCampaign($ctx->tenantId, $id, $ctx->user->id, "Client share link revoked by {$ctx->user->name}");

        return ['ok' => true];
    }

    /** Public read-only view. Excludes team names, notes, config, billing and other campaigns. */
    public static function publicCampaign(string $token): array
    {
        $c = Str::isUuid($token) ? DB::table('campaigns')->where('share_token', $token)->where('share_link_active', true)->first() : null;
        if (! $c) {
            throw Fail::notFound('This report link is no longer active.');
        }
        $s = Metrics::campaignStats($c->tenant_id, [$c->id])[$c->id];
        $ins = DB::table('insights')->where('tenant_id', $c->tenant_id)->where('campaign_id', $c->id)->whereNull('dismissed_at')->where('type', '<>', 'insufficient_evidence')
            ->orderByDesc('generated_at')->limit(20)->get()->all();
        $names = [];
        foreach (DB::table('users')->where('tenant_id', $c->tenant_id)->pluck('name') as $n) {
            $names[] = $n;
            $names[] = preg_split('/\s+/', $n)[0];
        }
        $names = array_values(array_filter(array_unique($names), fn ($n) => mb_strlen($n) > 1));
        usort($names, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $redact = fn (string $text) => array_reduce($names, fn ($t, $n) => preg_replace('/\b'.preg_quote($n, '/').'(?:\'s)?\b/u', 'The team', $t), $text);

        return [
            'campaign_name' => $c->name, 'status' => $c->status, 'lead_count' => $s['leadCount'], 'responded_count' => $s['respondedCount'], 'not_responded_count' => $s['notRespondedCount'],
            'speed_to_lead_ms' => $s['avgResponseMs'], 'health_pulse' => Metrics::health($s)['status'],
            'insights' => array_map(fn ($i) => ['observation' => $redact($i->observation), 'generated_at' => Fmt::iso($i->generated_at)], array_slice(Rules::orderInsights($ins), 0, 5)),
            'powered_by' => Platform::get()['branding']['productName'],
        ];
    }

    public static function memoryTimeline(Ctx $ctx, string $id): array
    {
        self::load($ctx, $id);

        return DB::table('campaign_changes')->leftJoin('users', 'users.id', '=', 'campaign_changes.changed_by')
            ->where('campaign_changes.tenant_id', $ctx->tenantId)->where('campaign_changes.campaign_id', $id)->orderBy('campaign_changes.changed_at')
            ->get(['campaign_changes.*', 'users.name as by'])->map(fn ($r) => [
                'id' => $r->id, 'type' => $r->change_type, 'description' => $r->change_description, 'by' => $r->by, 'at' => Fmt::iso($r->changed_at),
                'before' => Fmt::json($r->performance_before), 'after' => Fmt::json($r->performance_after),
            ])->all();
    }
}
