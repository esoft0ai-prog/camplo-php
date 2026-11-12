<?php

namespace App\Services;

use App\Domain\Rules;
use App\Support\Crypto;
use App\Support\Ctx;
use App\Support\Fail;
use App\Support\Fmt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Hosted pages — the read side (upload, redeploy and serving arrive in stage 3). */
final class PageService
{
    public static function webhookUrlFor(object $d): string
    {
        return config('camplo.app_url')."/api/v1/ingest/{$d->tenant_id}/{$d->id}";
    }

    public static function pageUrlFor(object $d): string
    {
        return config('camplo.app_url')."/sites/{$d->subdomain}/";
    }

    private static function analytics(string $tenantId, array $ids): array
    {
        $map = [];
        foreach ($ids as $id) {
            $map[$id] = ['visits7d' => 0, 'leads7d' => 0];
        }
        if (! $ids) {
            return $map;
        }
        foreach (DB::table('page_visits')->where('tenant_id', $tenantId)->whereIn('deployment_id', $ids)->where('day', '>=', gmdate('Y-m-d', time() - 7 * 86400))
            ->groupBy('deployment_id')->selectRaw('deployment_id as id, sum(visits) as n')->get() as $r) {
            $map[$r->id]['visits7d'] = (int) $r->n;
        }
        foreach (DB::table('leads')->where('tenant_id', $tenantId)->whereIn('deployment_id', $ids)->where('received_at', '>=', Fmt::ts(-7 * 86400))
            ->groupBy('deployment_id')->selectRaw('deployment_id as id, count(*) as n')->get() as $r) {
            $map[$r->id]['leads7d'] = (int) $r->n;
        }

        return $map;
    }

    public static function serialize(object $d, ?string $campaignName, ?object $hook, ?array $a, ?float $avgConv, ?object $domain, bool $canManage): array
    {
        $state = $hook ? Rules::webhookState($hook->last_received_at, (int) $hook->stale_threshold_minutes) : 'never_connected';
        $a ??= ['visits7d' => 0, 'leads7d' => 0];
        $conv = $a['visits7d'] ? $a['leads7d'] / $a['visits7d'] : null;
        $webhook = ['state' => $state, 'lastReceivedAt' => Fmt::iso($hook->last_received_at ?? null), 'url' => self::webhookUrlFor($d)];
        if ($canManage) {
            $webhook['secret'] = Crypto::decrypt($d->webhook_secret_encrypted);
        }

        return [
            'id' => $d->id, 'name' => $d->name, 'campaignId' => $d->campaign_id, 'campaignName' => $campaignName, 'subdomain' => $d->subdomain,
            'url' => self::pageUrlFor($d), 'host' => "{$d->subdomain}.".config('camplo.pages_base_domain'), 'status' => $d->status, 'servingState' => $d->serving_state, 'vip' => (bool) $d->vip,
            'entryFile' => $d->entry_file, 'storageSizeBytes' => (int) $d->storage_size_bytes, 'deployedAt' => Fmt::iso($d->deployed_at), 'createdAt' => Fmt::iso($d->created_at), 'failureReason' => $d->failure_reason,
            'webhook' => $webhook,
            'analytics' => [...$a, 'conversionRate' => $conv, 'aboveAverage' => $conv !== null && $avgConv !== null ? $conv > $avgConv : null],
            'hasRollbackAvailable' => Rules::hasRollbackAvailable($d->previous_storage_path, $d->previous_deployed_at, (int) config('camplo.rollback_retention_days')), 'previousDeployedAt' => Fmt::iso($d->previous_deployed_at),
            'earlyWarningActive' => Rules::earlyWarningActive($d->deployed_at, (int) config('camplo.early_warning_hours')), 'earlyWarningTriggered' => (bool) $d->early_warning_triggered,
            'domain' => $domain ? ['id' => $domain->id, 'name' => $domain->domain_name, 'status' => $domain->status] : null,
        ];
    }

    private static function hooksFor(array $ids): \Illuminate\Support\Collection
    {
        return $ids ? DB::table('webhook_sources')->whereIn('deployment_id', $ids)->get()->keyBy('deployment_id') : collect();
    }

    public static function list(Ctx $ctx, ?string $campaignId = null): array
    {
        if ($campaignId) {
            CampaignService::load($ctx, $campaignId);
        }
        $q = DB::table('deployments')->leftJoin('campaigns', 'campaigns.id', '=', 'deployments.campaign_id')->where('deployments.tenant_id', $ctx->tenantId)->where('deployments.status', '<>', 'deleted');
        if ($campaignId) {
            $q->where('deployments.campaign_id', $campaignId);
        }
        $rows = $q->orderByDesc('deployments.created_at')->get(['deployments.*', 'campaigns.name as cname']);
        $ids = $rows->pluck('id')->all();
        $a = self::analytics($ctx->tenantId, $ids);
        $avg = Metrics::workspaceConversion($ctx->tenantId);
        $hooks = self::hooksFor($ids);
        $doms = $ids ? DB::table('domains')->whereIn('deployment_id', $ids)->get()->keyBy('deployment_id') : collect();
        $canManage = $ctx->role !== 'member';

        return [
            'pages' => $rows->map(fn ($d) => self::serialize($d, $d->cname, $hooks[$d->id] ?? null, $a[$d->id] ?? null, $avg, $doms[$d->id] ?? null, $canManage))->all(),
            'avgConversionRate' => $avg,
        ];
    }

    public static function load(Ctx $ctx, string $id): object
    {
        $d = Str::isUuid($id) ? DB::table('deployments')->where('tenant_id', $ctx->tenantId)->where('id', $id)->where('status', '<>', 'deleted')->first() : null;
        if (! $d) {
            throw Fail::notFound('Page not found.');
        }

        return $d;
    }

    public static function get(Ctx $ctx, string $id): array
    {
        $d = self::load($ctx, $id);
        $cname = $d->campaign_id ? DB::table('campaigns')->where('id', $d->campaign_id)->value('name') : null;
        $lc = DB::table('leads')->where('tenant_id', $ctx->tenantId)->where('deployment_id', $id)
            ->selectRaw("count(*) as n, count(*) filter (where status = 'not_responded') as un, avg(extract(epoch from (responded_at - received_at))) filter (where responded_at is not null) as avg")->first();

        return [
            ...self::serialize($d, $cname, self::hooksFor([$id])[$id] ?? null, self::analytics($ctx->tenantId, [$id])[$id], Metrics::workspaceConversion($ctx->tenantId),
                DB::table('domains')->where('deployment_id', $id)->first(), $ctx->role !== 'member'),
            'leadSummary' => ['lead_count' => (int) $lc->n, 'unacknowledged_lead_count' => (int) $lc->un, 'avg_response_time_seconds' => $lc->avg === null ? null : (int) round((float) $lc->avg)],
        ];
    }

    public static function analyticsFor(Ctx $ctx, string $id): array
    {
        self::load($ctx, $id);
        $days = [];
        for ($i = 6; $i >= 0; $i--) {
            $days[gmdate('Y-m-d', time() - $i * 86400)] = ['date' => gmdate('Y-m-d', time() - $i * 86400), 'visits' => 0, 'leads' => 0];
        }
        $first = array_key_first($days);
        foreach (DB::table('page_visits')->where('deployment_id', $id)->where('day', '>=', $first)->get() as $r) {
            if (isset($days[$r->day])) {
                $days[$r->day]['visits'] = (int) $r->visits;
            }
        }
        foreach (DB::table('leads')->where('deployment_id', $id)->where('received_at', '>=', "{$first} 00:00:00+00")->groupByRaw('1')
            ->selectRaw("to_char(received_at at time zone 'UTC', 'YYYY-MM-DD') as day, count(*) as n")->get() as $r) {
            if (isset($days[$r->day])) {
                $days[$r->day]['leads'] = (int) $r->n;
            }
        }

        return ['daily' => array_values($days)];
    }

    public static function webhookHealth(Ctx $ctx, string $id): array
    {
        self::load($ctx, $id);
        $h = DB::table('webhook_sources')->where('deployment_id', $id)->first();

        return ['state' => $h ? Rules::webhookState($h->last_received_at, (int) $h->stale_threshold_minutes) : 'never_connected', 'lastReceivedAt' => Fmt::iso($h->last_received_at ?? null)];
    }

    /** Self-test: signs a sample payload with the page secret and checks it verifies. Creates no lead. */
    public static function testWebhook(Ctx $ctx, string $id): array
    {
        $d = self::load($ctx, $id);
        $t0 = microtime(true);
        $body = json_encode(['test' => true, 'full_name' => 'Camplo test']);
        $secret = Crypto::decrypt($d->webhook_secret_encrypted);
        $ok = LeadService::verifySignature($secret, $body, 'sha256='.Crypto::hmacHex($secret, $body));

        return ['ok' => $ok, 'latencyMs' => (int) round((microtime(true) - $t0) * 1000), 'url' => self::webhookUrlFor($d)];
    }
}
