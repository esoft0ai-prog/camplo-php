<?php

namespace App\Services;

use App\Domain\Rules;
use App\Support\Fmt;
use Illuminate\Support\Facades\DB;

final class Metrics
{
    /** Average received → responded time (ms) for leads responded in [since, until). */
    public static function avgResponseMs(string $tenantId, string $since, ?string $until = null, ?callable $extra = null): array
    {
        $q = DB::table('leads')->where('tenant_id', $tenantId)->whereNotNull('responded_at')
            ->where('responded_at', '>=', $since)->where('responded_at', '<', $until ?? Fmt::ts());
        if ($extra) {
            $extra($q);
        }
        $r = $q->selectRaw('avg(extract(epoch from (responded_at - received_at)) * 1000) as avg, count(*) as n')->first();

        return ['avgMs' => $r->avg === null ? null : (int) round((float) $r->avg), 'count' => (int) $r->n];
    }

    /** Workspace average page conversion (leads / visits, last 7 days). */
    public static function workspaceConversion(string $tenantId): ?float
    {
        $since = Fmt::ts(-7 * 86400);
        $v = (int) DB::table('page_visits')->where('tenant_id', $tenantId)->where('day', '>=', substr(gmdate('Y-m-d', time() - 7 * 86400), 0, 10))->sum('visits');
        $l = DB::table('leads')->where('tenant_id', $tenantId)->where('received_at', '>=', $since)->count();

        return $v > 0 ? $l / $v : null;
    }

    /** Share of the plan's Deep/Strategic budget used this month (non-BYOK). */
    public static function advancedUsage(string $tenantId, string $plan): float
    {
        $start = gmdate('Y-m-01 00:00:00+00');
        $total = (float) DB::table('ai_workload_logs')->where('tenant_id', $tenantId)->where('byok', false)
            ->whereIn('workload_level', ['deep', 'strategic'])->where('created_at', '>=', $start)->sum('cost_usd');
        $budget = Platform::get()['pricing']['plans'][$plan]['deepBudgetUsd'] ?: 1;

        return $total / $budget;
    }

    /**
     * Per-campaign aggregates computed from the operational database (never stored).
     * @return array<string,array> campaignId → stats
     */
    public static function campaignStats(string $tenantId, ?array $ids = null): array
    {
        $ids ??= DB::table('campaigns')->where('tenant_id', $tenantId)->pluck('id')->all();
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = ['leadCount' => 0, 'respondedCount' => 0, 'notRespondedCount' => 0, 'avgResponseMs' => null, 'pageCount' => 0, 'notesCount' => 0, 'leads7d' => 0, 'responded7d' => 0, 'avgWeekly' => 0, 'hookStatuses' => []];
        }
        if (! $ids) {
            return $out;
        }
        $d7 = Fmt::ts(-7 * 86400);
        $d35 = Fmt::ts(-35 * 86400);
        $rows = DB::table('leads')->where('tenant_id', $tenantId)->whereIn('campaign_id', $ids)->groupBy('campaign_id')
            ->selectRaw("campaign_id as cid, count(*) as total, count(*) filter (where status = 'responded') as responded,
                avg(extract(epoch from (responded_at - received_at)) * 1000) filter (where responded_at is not null) as avg_ms,
                count(*) filter (where received_at >= ?) as l7, count(*) filter (where received_at >= ? and status = 'responded') as r7,
                count(*) filter (where received_at >= ? and received_at < ?) as prior4w", [$d7, $d7, $d35, $d7])->get();
        foreach ($rows as $r) {
            $s = &$out[$r->cid];
            $s['leadCount'] = (int) $r->total;
            $s['respondedCount'] = (int) $r->responded;
            $s['notRespondedCount'] = $s['leadCount'] - $s['respondedCount'];
            $s['avgResponseMs'] = $r->avg_ms === null ? null : (int) round((float) $r->avg_ms);
            $s['leads7d'] = (int) $r->l7;
            $s['responded7d'] = (int) $r->r7;
            $s['avgWeekly'] = $r->prior4w / 4;
            unset($s);
        }
        foreach (DB::table('deployments')->where('tenant_id', $tenantId)->whereIn('campaign_id', $ids)->where('status', 'ready')->groupBy('campaign_id')->selectRaw('campaign_id as cid, count(*) as n')->get() as $r) {
            $out[$r->cid]['pageCount'] = (int) $r->n;
        }
        foreach (DB::table('notes')->where('tenant_id', $tenantId)->where('note_type', 'campaign')->whereIn('entity_id', $ids)->groupBy('entity_id')->selectRaw('entity_id as cid, count(*) as n')->get() as $r) {
            $out[$r->cid]['notesCount'] = (int) $r->n;
        }
        $hooks = DB::table('webhook_sources')->join('deployments', 'deployments.id', '=', 'webhook_sources.deployment_id')
            ->where('webhook_sources.tenant_id', $tenantId)->whereIn('webhook_sources.campaign_id', $ids)
            ->where('deployments.serving_state', 'active')->where('deployments.status', 'ready')
            ->get(['webhook_sources.campaign_id as cid', 'webhook_sources.last_received_at as last', 'webhook_sources.stale_threshold_minutes as thr']);
        foreach ($hooks as $h) {
            $st = Rules::webhookState($h->last, (int) $h->thr);
            if ($st !== 'never_connected' && isset($out[$h->cid])) {
                $out[$h->cid]['hookStatuses'][] = $st;
            }
        }

        return $out;
    }

    /** Health Pulse: { status, signals: { lead_volume, acknowledgment, webhook } }. */
    public static function health(array $s): array
    {
        $signals = [
            'lead_volume' => Rules::leadVolumeSignal($s['leads7d'], $s['avgWeekly']),
            'acknowledgment' => Rules::ackRateSignal($s['responded7d'], $s['leads7d']),
            'webhook' => Rules::webhookSignal($s['hookStatuses']),
        ];

        return ['status' => Rules::healthPulse(array_values($signals)), 'signals' => $signals];
    }

    public static function campaignCpl(object $c, array $s): ?float
    {
        $v = Rules::cpl($c->daily_spend === null ? null : (float) $c->daily_spend, $s['leadCount']);

        return $v;
    }
}
