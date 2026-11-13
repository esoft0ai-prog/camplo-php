<?php

namespace App\Services;

use App\Domain\Rules;
use App\Support\Ctx;
use App\Support\Fail;
use App\Support\Fmt;
use App\Support\Sql;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class SlaService
{
    private static function mine(?string $userId, ?string $campaignId = null): ?\Closure
    {
        return function (Builder $q) use ($userId, $campaignId) {
            if ($userId) {
                $q->where('responded_by', $userId);
            }
            if ($campaignId) {
                $q->where('campaign_id', $campaignId);
            }
        };
    }

    /** GET /sla/live — Speed-to-Lead (nav), comparisons and Morning Brief digest fields. */
    public static function live(Ctx $ctx): array
    {
        $tid = $ctx->tenantId;
        $startToday = Sql::day0(gmdate('Y-m-d'));
        $startYesterday = Sql::day0(gmdate('Y-m-d', time() - 86400));
        $d7 = Metrics::avgResponseMs($tid, Fmt::ts(-7 * 86400));
        $today = Metrics::avgResponseMs($tid, $startToday);
        $d30 = Metrics::avgResponseMs($tid, Fmt::ts(-30 * 86400));
        $yesterday = Metrics::avgResponseMs($tid, $startYesterday, $startToday);
        $mineY = Metrics::avgResponseMs($tid, $startYesterday, $startToday, self::mine($ctx->user->id));
        $mine30 = Metrics::avgResponseMs($tid, Fmt::ts(-30 * 86400), null, self::mine($ctx->user->id));
        $thr = (int) $ctx->tenant->sla_threshold_minutes;
        $y = DB::table('leads')->where('tenant_id', $tid)->where('received_at', '>=', $startYesterday)->where('received_at', '<', $startToday)
            ->selectRaw(Sql::q("count(*) as received, count(*) filter (where status = 'responded') as responded,
                count(*) filter (where extract(epoch from (coalesce(responded_at, now()) - received_at)) > ?::int * 60) as breaches"), [$thr])->first();
        [$od, $odB] = LeadService::overdueSql($ctx->tenant);
        $worst = DB::table('leads')->where('tenant_id', $tid)->whereRaw($od, $odB)->orderBy('received_at')->first(['id', 'full_name', 'received_at', 'campaign_id']);
        $flags = DB::table('insights')->where('tenant_id', $tid)->where('type', 'priority_flag')->whereNull('dismissed_at')->count();
        $flagged = DB::table('insights')->where('tenant_id', $tid)->whereIn('severity', ['red', 'amber'])->where('generated_at', '>=', $startYesterday)->where('generated_at', '<', $startToday)
            ->selectRaw('count(distinct campaign_id) as n')->value('n');
        $avg = $d7['avgMs'] ?? $d30['avgMs'];

        return [
            'avgResponseTime' => $avg, 'formatted' => Rules::formatDuration($avg), 'color' => Rules::speedColor($avg),
            'today' => $today['avgMs'], 'thirtyDayAvg' => $d30['avgMs'],
            'overdueCount' => LeadService::overdueCount($ctx->tenant),
            'brief' => [
                'yesterday' => ['received' => (int) $y->received, 'responded' => (int) $y->responded, 'slaBreaches' => (int) $y->breaches, 'campaignsFlagged' => (int) $flagged],
                'mostOverdue' => $worst ? ['id' => $worst->id, 'name' => $worst->full_name, 'receivedAt' => Fmt::iso($worst->received_at), 'campaignId' => $worst->campaign_id] : null,
                'priorityFlags' => $flags,
                'mySpeedYesterday' => $mineY['avgMs'], 'mySpeed30d' => $mine30['avgMs'], 'workspaceYesterday' => $yesterday['avgMs'],
            ],
        ];
    }

    public static function overdue(Ctx $ctx, ?string $campaignId = null): array
    {
        [$od, $odB] = LeadService::overdueSql($ctx->tenant);
        $q = DB::table('leads')->leftJoin('campaigns', 'campaigns.id', '=', 'leads.campaign_id')->leftJoin('deployments', 'deployments.id', '=', 'leads.deployment_id')
            ->leftJoin('users', 'users.id', '=', 'leads.assignee_id')->where('leads.tenant_id', $ctx->tenantId)->whereRaw($od, $odB);
        if ($campaignId) {
            $q->where('leads.campaign_id', $campaignId);
        }
        $rows = $q->orderBy('leads.received_at')->limit(100)->get(['leads.*', 'campaigns.name as cname', 'deployments.name as pname', 'users.name as aname']);

        return [
            'count' => $rows->count(),
            'leads' => $rows->map(fn ($r) => [
                'id' => $r->id, 'displayId' => LeadService::displayId($r->id), 'name' => $r->full_name, 'campaignId' => $r->campaign_id, 'campaignName' => $r->cname, 'pageName' => $r->pname,
                'receivedAt' => Fmt::iso($r->received_at), 'assigneeId' => $r->assignee_id, 'assigneeName' => $r->aname, 'vip' => (bool) $r->vip,
            ])->all(),
        ];
    }

    /** 7-day trend bars, or one day's detail when `date` is given. */
    public static function trend(Ctx $ctx, ?string $date = null, ?string $campaignId = null): array
    {
        $thr = (int) $ctx->tenant->sla_threshold_minutes;
        $scope = fn (Builder $q) => $campaignId ? $q->where('leads.campaign_id', $campaignId) : $q;
        if ($date) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw Fail::bad('Input validation failed');
            }
            $start = Sql::day0($date);
            $end = Sql::day0(gmdate('Y-m-d', strtotime("{$date} +1 day")));
            $d = $scope(DB::table('leads')->where('tenant_id', $ctx->tenantId)->where('received_at', '>=', $start)->where('received_at', '<', $end))
                ->selectRaw(Sql::q("count(*) as received, count(*) filter (where status = 'responded') as responded,
                    count(*) filter (where extract(epoch from (coalesce(responded_at, now()) - received_at)) > ?::int * 60) as overdue,
                    avg(extract(epoch from (responded_at - received_at))*1000) filter (where responded_at is not null) as avg,
                    min(extract(epoch from (responded_at - received_at))*1000) as min, max(extract(epoch from (responded_at - received_at))*1000) as max"), [$thr])->first();
            $team = $scope(DB::table('leads')->join('users', 'users.id', '=', 'leads.responded_by')->where('leads.tenant_id', $ctx->tenantId)
                ->where('leads.received_at', '>=', $start)->where('leads.received_at', '<', $end))
                ->groupBy('users.id', 'users.name')->selectRaw(Sql::q('users.id, users.name, avg(extract(epoch from (leads.responded_at - leads.received_at))*1000) as avg'))
                ->orderByRaw('3 asc')->limit(3)->get();
            $avg = $d->avg === null ? null : (int) round((float) $d->avg);

            return [
                'date' => $date, 'avgMs' => $avg, 'color' => Rules::speedColor($avg), 'received' => (int) $d->received, 'responded' => (int) $d->responded, 'overdue' => (int) $d->overdue,
                'fastestMs' => $d->min === null ? null : (int) round((float) $d->min), 'slowestMs' => $d->max === null ? null : (int) round((float) $d->max),
                'slowestOverThreshold' => $d->max !== null && (float) $d->max > $thr * 60000,
                'team' => $team->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'avgMs' => (int) round((float) $t->avg)])->all(),
            ];
        }
        $since = gmdate('Y-m-d', time() - 6 * 86400);
        $rows = $scope(DB::table('leads')->where('tenant_id', $ctx->tenantId)->where('received_at', '>=', Sql::day0($since)))
            ->groupByRaw('1')->selectRaw(Sql::q("to_char(received_at at time zone 'UTC', 'YYYY-MM-DD') as day,
                avg(extract(epoch from (responded_at - received_at))*1000) filter (where responded_at is not null) as avg, count(*) as n"))->get()->keyBy('day');
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $date = gmdate('Y-m-d', strtotime("{$since} +{$i} day"));
            $r = $rows[$date] ?? null;
            $avg = $r && $r->avg !== null ? (int) round((float) $r->avg) : null;
            $days[] = ['date' => $date, 'label' => gmdate('D', strtotime("{$date} 12:00:00 UTC")), 'avgMs' => $avg, 'color' => Rules::speedColor($avg), 'leads' => (int) ($r->n ?? 0)];
        }

        return ['days' => $days];
    }

    public static function team(Ctx $ctx, ?string $campaignId = null): array
    {
        $members = DB::table('users')->where('tenant_id', $ctx->tenantId)->whereNull('removed_at')->whereNotNull('joined_at')->get();
        $thr = (int) $ctx->tenant->sla_threshold_minutes;
        $week = Fmt::ts(-7 * 86400);
        $out = [];
        foreach ($members as $m) {
            $w = Metrics::avgResponseMs($ctx->tenantId, $week, null, self::mine($m->id, $campaignId));
            $mo = Metrics::avgResponseMs($ctx->tenantId, Fmt::ts(-30 * 86400), null, self::mine($m->id, $campaignId));
            $q = DB::table('leads')->where('tenant_id', $ctx->tenantId)->where('assignee_id', $m->id)->where('received_at', '>=', $week);
            if ($campaignId) {
                $q->where('campaign_id', $campaignId);
            }
            $a = $q->selectRaw(Sql::q("count(*) as assigned, count(*) filter (where status = 'responded') as responded,
                count(*) filter (where extract(epoch from (coalesce(responded_at, now()) - received_at)) > ?::int * 60) as breaches"), [$thr])->first();
            $out[] = [
                'id' => $m->id, 'name' => $m->name, 'avgThisWeekMs' => $w['avgMs'], 'avg30dMs' => $mo['avgMs'],
                'improving' => $w['avgMs'] !== null && $mo['avgMs'] !== null ? $w['avgMs'] <= $mo['avgMs'] : null,
                'acknowledgmentRate' => $a->assigned ? $a->responded / $a->assigned : null, 'breachesThisWeek' => (int) $a->breaches,
            ];
        }
        // Alphabetical — data only, never a ranking.
        usort($out, fn ($x, $y) => strcmp($x['name'], $y['name']));

        return $out;
    }

    public static function campaignSla(Ctx $ctx, string $campaignId): array
    {
        $s = Metrics::avgResponseMs($ctx->tenantId, Sql::day0('1970-01-01'), null, self::mine(null, $campaignId));
        $c = DB::table('leads')->where('tenant_id', $ctx->tenantId)->where('campaign_id', $campaignId)->selectRaw(Sql::q("count(*) as n, count(*) filter (where status = 'responded') as r"))->first();

        return ['avgResponseMs' => $s['avgMs'], 'color' => Rules::speedColor($s['avgMs']), 'leadCount' => (int) $c->n, 'respondedCount' => (int) $c->r, 'thresholdMinutes' => (int) $ctx->tenant->sla_threshold_minutes];
    }

    /** POST /sla/notify/{leadId} — email (and Telegram) with an acknowledgment link. */
    public static function notifyNow(Ctx $ctx, string $leadId): array
    {
        $l = DB::table('leads')->where('tenant_id', $ctx->tenantId)->where('id', $leadId)->first();
        if (! $l) {
            throw Fail::notFound('Lead not found.');
        }
        if ($l->status === 'responded') {
            throw Fail::conflict('This lead has already been responded to.');
        }
        $r = self::sendAlert($ctx->tenant, $l, true);
        if (! $r['sent']) {
            throw Fail::bad('Last notification failed. Try again.');
        }

        return ['ok' => true, 'channels' => $r['channels']];
    }

    /** Alert the assignee (or every active member when unassigned): Telegram first when connected, email as fallback. */
    public static function sendAlert(object $tenant, object $l, bool $manual = false): array
    {
        $recipients = $l->assignee_id
            ? DB::table('users')->where('id', $l->assignee_id)->whereNull('removed_at')->get()
            : DB::table('users')->where('tenant_id', $tenant->id)->whereNull('removed_at')->whereNotNull('joined_at')->where('notify_enabled', true)->get();
        $dep = $l->deployment_id ? DB::table('deployments')->where('id', $l->deployment_id)->value('name') : null;
        $minutes = (int) round((Rules::nowMs() - Rules::ms($l->received_at)) / Rules::MIN);
        $channels = [];
        foreach ($recipients as $u) {
            $link = LeadService::createAckToken($tenant->id, $l->id, $u->id);
            $delivered = false;
            if ($u->telegram_chat_id && $u->notify_channel !== 'email' && Telegram::sendLeadMessage($tenant->id, $u, $l->id, "⏱ {$l->full_name} has waited {$minutes} min (".($dep ?? 'webhook').').')) {
                $delivered = true;
                $channels['telegram'] = true;
            }
            if (! $delivered || $u->notify_channel === 'both') {
                Mailer::send(Mailer::slaAlert($u->email, $l->full_name, $dep ?? 'webhook', $minutes, $link));
                $channels['email'] = true;
            }
        }
        if (! $manual) {
            DB::table('leads')->where('id', $l->id)->update(['sla_alert_sent_at' => now()]);
            Effects::notify($tenant->id, ['userId' => $l->assignee_id, 'kind' => 'sla_breach', 'description' => "SLA breached — {$l->full_name} ({$minutes} min)", 'link' => "/leads/{$l->id}"]);
            Effects::remember($tenant->id, ['type' => 'sla_breach', 'campaignId' => $l->campaign_id, 'entityId' => $l->id, 'content' => "SLA breached: {$l->full_name} waited {$minutes} minutes against a {$tenant->sla_threshold_minutes}-minute threshold."]);
            Effects::fireOutbound($tenant->id, 'sla.breached', ['lead_id' => $l->id, 'name' => $l->full_name, 'waited_minutes' => $minutes]);
            Effects::emit($tenant->id, 'workspace:overdue-count');
        }

        return ['sent' => $recipients->isNotEmpty(), 'channels' => array_keys($channels)];
    }

    // ------------------------------------------------------------- configuration

    public static function config(Ctx $ctx): array
    {
        $t = $ctx->tenant;

        return [
            'sla_threshold_minutes' => (int) $t->sla_threshold_minutes, 'vip_lead_enabled' => (bool) $t->vip_lead_enabled,
            'vip_sla_threshold_minutes' => (int) $t->vip_sla_threshold_minutes, 'daily_summary_time' => substr($t->daily_summary_time, 0, 5),
            'vipPages' => DB::table('deployments')->where('tenant_id', $ctx->tenantId)->where('status', 'ready')->get(['id', 'name', 'vip'])
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'vip' => (bool) $p->vip])->all(),
            'notificationRules' => DB::table('users')->where('tenant_id', $ctx->tenantId)->whereNull('removed_at')->whereNotNull('joined_at')->get(['id', 'name', 'notify_enabled', 'notify_channel'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'notifyEnabled' => (bool) $u->notify_enabled, 'notifyChannel' => $u->notify_channel])->all(),
        ];
    }

    public static function patchConfig(Ctx $ctx, array $p): array
    {
        $ctx->assertPerm('sla.manage');
        $set = [];
        foreach (['sla_threshold_minutes', 'vip_sla_threshold_minutes'] as $k) {
            if (array_key_exists($k, $p) && $p[$k] !== null) {
                if ($p[$k] <= 0) {
                    throw Fail::bad('Please enter a value greater than 0.');
                }
                $set[$k] = (int) round($p[$k]);
            }
        }
        if (array_key_exists('vip_lead_enabled', $p) && $p['vip_lead_enabled'] !== null) {
            $set['vip_lead_enabled'] = (bool) $p['vip_lead_enabled'];
        }
        if (! empty($p['daily_summary_time'])) {
            if (! preg_match('/^\d{2}:\d{2}$/', $p['daily_summary_time'])) {
                throw Fail::bad('Use HH:MM (UTC).');
            }
            $set['daily_summary_time'] = $p['daily_summary_time'].':00';
        }
        if ($set) {
            DB::table('tenants')->where('id', $ctx->tenantId)->update($set);
        }
        if (isset($p['vipPages']) && is_array($p['vipPages'])) {
            DB::table('deployments')->where('tenant_id', $ctx->tenantId)->update(['vip' => false]);
            if ($p['vipPages']) {
                DB::table('deployments')->where('tenant_id', $ctx->tenantId)->whereIn('id', $p['vipPages'])->update(['vip' => true]);
            }
        }
        foreach ($p['notificationRules'] ?? [] as $r) {
            DB::table('users')->where('tenant_id', $ctx->tenantId)->where('id', $r['id'])->update(['notify_enabled' => (bool) $r['notifyEnabled'], 'notify_channel' => $r['notifyChannel']]);
        }
        Effects::emit($ctx->tenantId, 'workspace:sla');
        $vip = $set['vip_sla_threshold_minutes'] ?? (int) $ctx->tenant->vip_sla_threshold_minutes;
        $std = $set['sla_threshold_minutes'] ?? (int) $ctx->tenant->sla_threshold_minutes;

        return ['ok' => true, 'warnings' => $vip >= $std ? ['VIP threshold is usually lower than the standard threshold'] : []];
    }

    private const DEFAULT_RULES = [
        'crm' => [['not_contacted', 24, 'hours', true], ['not_proposal', 72, 'hours', false], ['no_activity', 7, 'days', false]],
        'email' => [['not_enrolled', 2, 'hours', true], ['open_rate_drop', 15, 'percent', false], ['no_click', 48, 'hours', false]],
        'ads' => [['spend_without_leads', 72, 'hours', true], ['cpl_exceeded', 0, 'currency', false], ['spend_increase', 20, 'percent', false]],
        'slack' => [['discussed_not_acted', 4, 'hours', false]],
    ];

    public static function categoryOf(string $p): ?string
    {
        return match (true) {
            in_array($p, ['gohighlevel', 'twenty_crm', 'hubspot', 'salesforce'], true) => 'crm',
            in_array($p, ['activecampaign', 'mailchimp', 'brevo', 'notifuse'], true) => 'email',
            in_array($p, ['meta_ads', 'google_ads'], true) => 'ads',
            $p === 'slack' => 'slack',
            default => null,
        };
    }

    private static function ruleOut(object $r): array
    {
        return ['id' => $r->id, 'ruleType' => $r->rule_type, 'thresholdValue' => $r->threshold_value + 0, 'thresholdUnit' => $r->threshold_unit, 'enabled' => (bool) $r->enabled, 'notificationChannels' => Fmt::json($r->notification_channels)];
    }

    public static function crossToolConfig(Ctx $ctx): array
    {
        $ctx->assertFeature('cross_tool_sla');
        $out = [];
        foreach (DB::table('integrations')->where('tenant_id', $ctx->tenantId)->where('status', 'connected')->get() as $i) {
            $cat = self::categoryOf($i->provider);
            if (! $cat) {
                continue;
            }
            $q = fn () => DB::table('cross_tool_sla_rules')->where('tenant_id', $ctx->tenantId)->where('integration_id', $i->id)->orderBy('created_at')->get();
            $rules = $q();
            if ($rules->isEmpty()) {
                DB::table('cross_tool_sla_rules')->insert(array_map(fn ($r) => ['tenant_id' => $ctx->tenantId, 'integration_id' => $i->id, 'rule_type' => $r[0], 'threshold_value' => $r[1], 'threshold_unit' => $r[2], 'enabled' => $r[3]], self::DEFAULT_RULES[$cat]));
                $rules = $q();
            }
            $out[] = ['integrationId' => $i->id, 'provider' => $i->provider, 'category' => $cat, 'method' => $i->connection_method, 'rules' => $rules->map(fn ($r) => self::ruleOut($r))->all()];
        }

        return $out;
    }

    public static function patchCrossTool(Ctx $ctx, string $integrationId, array $rules): array
    {
        $ctx->assertPerm('sla.manage');
        $ctx->assertFeature('cross_tool_sla');
        foreach ($rules as $r) {
            if ($r['thresholdValue'] < 0) {
                throw Fail::bad('Thresholds cannot be negative.');
            }
            DB::table('cross_tool_sla_rules')->where('tenant_id', $ctx->tenantId)->where('integration_id', $integrationId)->where('id', $r['id'])
                ->update(['threshold_value' => $r['thresholdValue'], 'enabled' => (bool) $r['enabled'], 'notification_channels' => json_encode(array_values($r['notificationChannels'])), 'updated_at' => now()]);
        }

        return ['ok' => true];
    }

    public static function crossToolBreaches(Ctx $ctx): array
    {
        $ctx->assertFeature('cross_tool_sla');
        $connected = DB::table('integrations')->where('tenant_id', $ctx->tenantId)->where('status', 'connected')->exists();
        $rows = DB::table('cross_tool_sla_breaches as b')->join('leads', 'leads.id', '=', 'b.lead_id')->join('integrations', 'integrations.id', '=', 'b.integration_id')
            ->leftJoin('users', 'users.id', '=', 'leads.assignee_id')->where('b.tenant_id', $ctx->tenantId)->where('b.resolved', false)->orderByDesc('b.detected_at')->limit(100)
            ->get(['b.*', 'leads.full_name as lead', 'users.name as assignee', 'integrations.provider']);

        return [
            'connected' => $connected,
            'breaches' => $rows->map(fn ($r) => ['id' => $r->id, 'type' => self::categoryOf($r->provider), 'provider' => $r->provider, 'breachType' => $r->breach_type, 'leadId' => $r->lead_id,
                'leadName' => $r->lead, 'assigneeName' => $r->assignee, 'hoursExceeded' => $r->hours_exceeded === null ? null : $r->hours_exceeded + 0, 'detectedAt' => Fmt::iso($r->detected_at)])->all(),
        ];
    }
}
