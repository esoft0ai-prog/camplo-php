<?php

namespace App\Services;

use App\Domain\Rules;
use App\Support\Errors;
use App\Support\Fmt;
use App\Support\Sql;
use App\Support\Storage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Every time-based rule as an idempotent sweep, run each minute by `php artisan camplo:sweep` (the scheduler) and
 * by GET /api/cron. Safe to run concurrently with requests and on several instances (a cache lock guards it).
 */
final class Sweeps
{
    public const SUSPENDED_OFFLINE = 'suspended';

    public static function run(): array
    {
        $lock = Cache::lock('camplo:sweeps', 300);
        if (! $lock->get()) {
            return ['skipped' => true];
        }
        $done = [];
        try {
            foreach ([
                'sla' => fn () => self::sla(), 'webhooks' => fn () => self::webhooks(), 'team-notes' => fn () => NoteService::expireTeamNotes(),
                'digest' => fn () => self::digest(), 'early-warning' => fn () => self::earlyWarning(), 'rollback' => fn () => self::rollbackCleanup(),
                'suspension' => fn () => self::suspension(), 'campaign-status' => fn () => self::campaignStatus(), 'rt-prune' => fn () => Rt::prune(),
                'weekly-digest-plugin' => fn () => Plugins::runWeeklyDigests(),
                // every 30 minutes
                'cross-tool' => fn () => Cache::add('sweep:cross-tool', 1, 1800) ? CrossToolService::check() : 'not due',
                'ai' => fn () => self::ai(),
            ] as $label => $fn) {
                try {
                    $done[$label] = $fn();
                } catch (Throwable $e) {
                    Errors::capture($e, ['source' => 'job', 'route' => "sweep:{$label}"]);
                    $done[$label] = 'error';
                }
            }
        } finally {
            $lock->release();
        }

        return $done;
    }

    /** Insights refresh for every active workspace (each respects its own interval), then memory measurement. */
    public static function ai(): int
    {
        $n = 0;
        foreach (DB::table('tenants')->where('status', 'active')->pluck('id') as $id) {
            try {
                $n += empty(Engine::refresh($id)['skipped']) ? 1 : 0;
            } catch (Throwable $e) {
                Errors::capture($e, ['source' => 'job', 'route' => 'sweep:ai', 'tenantId' => $id]);
            }
        }
        Engine::measureMemory();

        return $n;
    }

    /** SLA alerts for leads past their threshold — idempotent via sla_alert_sent_at. */
    public static function sla(): int
    {
        $n = 0;
        foreach (DB::table('tenants')->where('status', 'active')->where('urgent_alerts_enabled', true)->get() as $t) {
            $vip = $t->vip_lead_enabled ? (int) $t->vip_sla_threshold_minutes : (int) $t->sla_threshold_minutes;
            $due = DB::table('leads')->where('tenant_id', $t->id)->where('status', 'not_responded')->whereNull('sla_alert_sent_at')
                ->whereRaw(Sql::q("received_at < now() - (case when vip then ?::int else ?::int end) * interval '1 minute'"), [$vip, (int) $t->sla_threshold_minutes])->limit(50)->get();
            foreach ($due as $l) {
                SlaService::sendAlert($t, $l);
                $n++;
            }
            if ($due->isNotEmpty()) {
                Engine::trigger($t->id, 'sla_breach');
            }
        }

        return $n;
    }

    public static function webhooks(): int
    {
        $n = 0;
        foreach (DB::table('webhook_sources')->whereNotNull('last_received_at')->get() as $h) {
            $s = Rules::webhookState($h->last_received_at, (int) $h->stale_threshold_minutes);
            if ($s !== 'never_connected' && $s !== $h->status) {
                DB::table('webhook_sources')->where('id', $h->id)->update(['status' => $s]);
                Rt::emit($h->tenant_id, "page:{$h->deployment_id}:webhook", ['status' => $s]);
                if ($s === 'offline') {
                    Engine::trigger($h->tenant_id, 'webhook_silence');
                }
                $n++;
            }
        }

        return $n;
    }

    /** One daily summary per workspace once its summary time (UTC) has passed today. */
    public static function digest(): int
    {
        $hhmm = gmdate('H:i');
        $day = gmdate('Y-m-d');
        $n = 0;
        foreach (DB::table('tenants')->where('status', 'active')->where('daily_summary_enabled', true)->get() as $t) {
            if (substr($t->daily_summary_time, 0, 5) > $hhmm || ! Cache::add("digest:{$t->id}:{$day}", 1, 90000)) {
                continue;
            }
            self::sendDigest($t);
            $n++;
        }

        return $n;
    }

    public static function sendDigest(object $t): void
    {
        $open = DB::table('leads')->leftJoin('deployments', 'deployments.id', '=', 'leads.deployment_id')->where('leads.tenant_id', $t->id)->where('leads.status', 'not_responded')
            ->orderBy('leads.received_at')->limit(50)->get(['leads.full_name', 'leads.received_at', 'deployments.name as dep']);
        if ($open->isEmpty()) {
            return;
        }
        $avg = DB::table('leads')->where('tenant_id', $t->id)->whereRaw(Sql::q("responded_at >= now() - interval '1 day'"))->selectRaw(Sql::q('avg(extract(epoch from (responded_at - received_at))*1000) as ms'))->value('ms');
        $lines = $open->map(fn ($l) => "• {$l->full_name} — ".($l->dep ?? 'webhook').' — waiting '.Rules::formatDuration(Rules::nowMs() - Rules::ms($l->received_at)))->all();
        Mailer::send(Mailer::dailySummary($t->notification_email, $lines, Rules::formatDuration($avg === null ? null : (float) $avg)));
    }

    /** Closes 72-hour early-warning windows that have run their course. */
    public static function earlyWarning(): int
    {
        $rows = Sql::updateReturning('deployments', 'early_warning_active = false, updated_at = now()', [], 'early_warning_active = true and deployed_at < ?', [Fmt::ts(-(int) config('camplo.early_warning_hours') * 3600)], 'id, tenant_id');
        foreach ($rows as $d) {
            Rt::emit($d->tenant_id, "page:{$d->id}:webhook", ['earlyWarningActive' => false]);
        }

        return count($rows);
    }

    public static function rollbackCleanup(): int
    {
        $old = DB::table('deployments')->whereNotNull('previous_storage_path')->where('previous_deployed_at', '<', Fmt::ts(-(int) config('camplo.rollback_retention_days') * 86400))->get();
        foreach ($old as $d) {
            Storage::removePrefix($d->previous_storage_path);
            DB::table('deployments')->where('id', $d->id)->update(['previous_storage_path' => null, 'previous_deployed_at' => null]);
        }

        return $old->count();
    }

    /** Pages stay live 7 days after suspension, then stop being served (files are retained). */
    public static function suspension(): int
    {
        $ids = DB::table('tenants')->where('status', 'suspended')->where('suspended_at', '<', Fmt::ts(-7 * 86400))->pluck('id')->all();
        if (! $ids) {
            return 0;
        }
        DB::table('deployments')->whereIn('tenant_id', $ids)->where('status', 'ready')->update(['status' => 'deleted', 'failure_reason' => self::SUSPENDED_OFFLINE]);

        return count($ids);
    }

    /** Keep campaigns.status in step with the rule-based Health Pulse for running campaigns. */
    public static function campaignStatus(?string $tenantId = null): int
    {
        $q = DB::table('campaigns')->join('tenants', 'tenants.id', '=', 'campaigns.tenant_id')->whereIn('campaigns.status', ['active', 'watch', 'critical'])->where('tenants.status', 'active');
        if ($tenantId) {
            $q->where('campaigns.tenant_id', $tenantId);
        }
        $changed = 0;
        foreach ($q->get(['campaigns.*'])->groupBy('tenant_id') as $tid => $list) {
            $stats = Metrics::campaignStats($tid, $list->pluck('id')->all());
            foreach ($list as $c) {
                $h = Metrics::health($stats[$c->id]);
                $status = $h['status'] === 'healthy' ? 'active' : $h['status'];
                if ($status === $c->status) {
                    continue;
                }
                $changed++;
                DB::table('campaigns')->where('id', $c->id)->update(['status' => $status, 'updated_at' => now()]);
                Rt::emit($tid, "campaign:{$c->id}:health", ['status' => $status, 'pulse' => $h['status'], 'signals' => $h['signals']]);
                if ($status === 'critical') {
                    Engine::trigger($tid, 'health_pulse_change');
                }
            }
        }

        return $changed;
    }
}
