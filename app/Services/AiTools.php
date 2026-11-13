<?php

namespace App\Services;

use App\Domain\Rules;
use App\Support\Fmt;
use App\Support\Sql;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The controlled tool set for the Main Agent and Workers. Every tool is read-only and receives its tenant/campaign
 * scope from the caller's contract — never from the model — so a model cannot widen its own access.
 */
final class AiTools
{
    public const DESCRIPTIONS = [
        'get_campaign_metrics' => 'Lead volume, acknowledgment, speed-to-lead, CPL and Health Pulse for one campaign (campaignId) or all campaigns.',
        'get_lead_data' => 'Unresponded and overdue leads (with wait times and assignees) for a campaign or the workspace.',
        'get_funnel_data' => 'Funnel from lead → responded → CRM lifecycle milestones for a campaign (last 30 days).',
        'get_campaign_history' => 'Campaign memory: prior changes, experiments and recommendation outcomes (what was already tried).',
        'get_webhook_health' => 'Webhook health of every page (operational / stale / offline / never connected).',
        'get_team_patterns' => 'Per-member response times and SLA breaches over the last 7 days (pattern data, no ranking).',
        'get_conversation_signals' => 'Recent campaign and lead notes written by the team (summarise patterns; never quote individuals).',
        'get_data_sources' => 'Which of the six data sources are connected. Use this to state which sources an analysis is based on.',
    ];

    /** Campaign boundary: a scoped contract always wins over a model-supplied id. */
    private static function campaignFor(?string $scoped, array $args): ?string
    {
        if ($scoped) {
            return $scoped;
        }

        return is_string($args['campaignId'] ?? null) && Str::isUuid($args['campaignId']) ? $args['campaignId'] : null;
    }

    public static function call(string $name, string $tenantId, ?string $scopedCampaign, array $args = []): mixed
    {
        $cid = self::campaignFor($scopedCampaign, $args);

        return match ($name) {
            'get_campaign_metrics' => self::metrics($tenantId, $cid),
            'get_lead_data' => self::leadData($tenantId, $cid),
            'get_funnel_data' => self::funnel($tenantId, $cid),
            'get_campaign_history' => Memory::recall($tenantId, (string) ($args['query'] ?? 'What changed on this campaign and what happened?'), $cid),
            'get_webhook_health' => self::webhooks($tenantId, $cid),
            'get_team_patterns' => self::team($tenantId),
            'get_conversation_signals' => self::notes($tenantId, $cid),
            'get_data_sources' => self::sources($tenantId),
            default => throw new \InvalidArgumentException("unknown tool {$name}"),
        };
    }

    public static function metrics(string $tenantId, ?string $cid): array
    {
        $q = DB::table('campaigns')->where('tenant_id', $tenantId);
        if ($cid) {
            $q->where('id', $cid);
        }
        $rows = $q->get();
        $stats = Metrics::campaignStats($tenantId, $rows->pluck('id')->all());

        return $rows->map(function ($c) use ($stats) {
            $st = $stats[$c->id];
            $h = Metrics::health($st);

            return [
                'id' => $c->id, 'name' => $c->name, 'status' => $c->status, 'leads_total' => $st['leadCount'], 'leads_7d' => $st['leads7d'], 'avg_weekly_prior_4w' => round($st['avgWeekly'], 1),
                'responded' => $st['respondedCount'], 'ack_rate_7d' => $st['leads7d'] ? round($st['responded7d'] / $st['leads7d'], 2) : null,
                'avg_speed_to_lead' => Rules::formatDuration($st['avgResponseMs']), 'cpl' => Metrics::campaignCpl($c, $st), 'cpl_threshold' => Fmt::num($c->cpl_threshold),
                'daily_spend' => Fmt::num($c->daily_spend), 'currency' => $c->currency, 'health' => $h['status'], 'health_signals' => $h['signals'],
                'days_active' => intdiv(Rules::nowMs() - Rules::ms($c->start_date.'T00:00:00Z'), Rules::DAY),
            ];
        })->all();
    }

    public static function leadData(string $tenantId, ?string $cid): array
    {
        $thr = (int) DB::table('tenants')->where('id', $tenantId)->value('sla_threshold_minutes');
        $q = DB::table('leads')->leftJoin('users', 'users.id', '=', 'leads.assignee_id')->leftJoin('campaigns', 'campaigns.id', '=', 'leads.campaign_id')
            ->where('leads.tenant_id', $tenantId)->where('leads.status', 'not_responded');
        if ($cid) {
            $q->where('leads.campaign_id', $cid);
        }
        $now = Rules::nowMs();

        return [
            'threshold_minutes' => $thr,
            'unresponded' => $q->orderBy('leads.received_at')->limit(25)->get(['leads.*', 'users.name as assignee', 'campaigns.name as campaign'])->map(fn ($r) => [
                'lead_id' => $r->id, 'name' => $r->full_name, 'campaign' => $r->campaign, 'waiting' => Rules::formatDuration($now - Rules::ms($r->received_at)),
                'overdue' => $now - Rules::ms($r->received_at) > $thr * Rules::MIN, 'assignee' => $r->assignee ?? 'Unassigned', 'vip' => (bool) $r->vip,
            ])->all(),
        ];
    }

    private static function funnel(string $tenantId, ?string $cid): array
    {
        $scope = function ($q) use ($tenantId, $cid) {
            $q->where('leads.tenant_id', $tenantId)->where('leads.received_at', '>=', Fmt::ts(-30 * 86400));
            if ($cid) {
                $q->where('leads.campaign_id', $cid);
            }

            return $q;
        };
        $f = $scope(DB::table('leads'))->selectRaw(Sql::q("count(*) as n, count(*) filter (where leads.status = 'responded') as r, count(*) filter (where leads.has_external_lifecycle_events) as ext"))->first();
        $ev = $scope(DB::table('lead_lifecycle_events as e')->join('leads', 'leads.id', '=', 'e.lead_id'))->where('e.source', '<>', 'camplo')->groupBy('e.event')
            ->selectRaw('e.event, count(distinct e.lead_id) as n')->get();

        return ['leads' => (int) $f->n, 'responded' => (int) $f->r, 'with_crm_events' => (int) $f->ext, 'crm_stages' => $ev->map(fn ($e) => ['stage' => $e->event, 'leads' => (int) $e->n])->all()];
    }

    public static function webhooks(string $tenantId, ?string $cid): array
    {
        $q = DB::table('deployments')->leftJoin('webhook_sources', 'webhook_sources.deployment_id', '=', 'deployments.id')->where('deployments.tenant_id', $tenantId)->where('deployments.status', 'ready');
        if ($cid) {
            $q->where('deployments.campaign_id', $cid);
        }

        return $q->get(['deployments.name', 'webhook_sources.last_received_at as last', 'webhook_sources.stale_threshold_minutes as thr', 'deployments.serving_state as state'])
            ->map(fn ($r) => ['page' => $r->name, 'serving' => $r->state, 'webhook' => Rules::webhookState($r->last, (int) ($r->thr ?? 480)), 'last_received' => Fmt::iso($r->last)])->all();
    }

    private static function team(string $tenantId): array
    {
        return DB::table('leads')->join('users', 'users.id', '=', 'leads.responded_by')->where('leads.tenant_id', $tenantId)->where('leads.received_at', '>=', Fmt::ts(-7 * 86400))
            ->groupBy('users.name')->selectRaw(Sql::q('users.name, count(*) as n, avg(extract(epoch from (leads.responded_at - leads.received_at))*1000) as avg, count(*) filter (where extract(isodow from leads.received_at) >= 6) as weekend'))
            ->get()->map(fn ($r) => ['member' => $r->name, 'responded' => (int) $r->n, 'avg_response' => Rules::formatDuration($r->avg === null ? null : (float) $r->avg), 'weekend_leads' => (int) $r->weekend])->all();
    }

    private static function notes(string $tenantId, ?string $cid): array
    {
        $q = DB::table('notes')->where('tenant_id', $tenantId);
        if ($cid) {
            $ids = [$cid, ...DB::table('leads')->where('tenant_id', $tenantId)->where('campaign_id', $cid)->limit(500)->pluck('id')->all()];
            $q->whereIn('entity_id', $ids);
        }

        return $q->orderByDesc('created_at')->limit(30)->get(['content', 'created_at'])->map(fn ($r) => ['note' => mb_substr($r->content, 0, 400), 'at' => Fmt::iso($r->created_at)])->all();
    }

    public static function sources(string $tenantId): array
    {
        $ps = DB::table('integrations')->where('tenant_id', $tenantId)->where('status', 'connected')->pluck('provider')->all();
        $any = fn (array $list) => (bool) array_intersect($ps, $list);

        return [
            'camplo_native' => true, 'analytics_umami' => in_array('umami', $ps, true), 'email_platform' => $any(['activecampaign', 'mailchimp', 'brevo', 'notifuse']),
            'crm' => $any(['twenty_crm', 'gohighlevel', 'hubspot', 'salesforce']), 'slack' => in_array('slack', $ps, true), 'ad_platforms' => $any(['meta_ads', 'google_ads']),
        ];
    }

    /** Compact workspace snapshot for the Main Agent's context. */
    public static function snapshot(string $tenantId): array
    {
        return [
            'campaigns' => self::metrics($tenantId, null), 'leads' => self::leadData($tenantId, null), 'data_sources' => self::sources($tenantId),
            'webhooks' => self::webhooks($tenantId, null), 'team' => DB::table('users')->where('tenant_id', $tenantId)->whereNull('removed_at')->pluck('name')->all(),
        ];
    }
}
