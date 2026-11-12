<?php

namespace App\Services;

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
}
