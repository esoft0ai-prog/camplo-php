<?php

namespace App\Services;

use App\Domain\Rules;
use App\Support\Crypto;
use App\Support\Ctx;
use App\Support\Fail;
use App\Support\Fmt;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LeadService
{
    public static function displayId(string $id): string
    {
        return WorkspaceService::displayId($id);
    }

    public static function serialize(object $l, object $t, ?array $viewer = null): array
    {
        $now = Rules::nowMs();
        $thr = Rules::thresholdMinutesFor($l, $t);
        $utmOn = Rules::hasFeature($t->plan, 'utm');
        $utm = $utmOn ? ['source' => $l->utm_source, 'medium' => $l->utm_medium, 'campaign' => $l->utm_campaign, 'content' => $l->utm_content, 'term' => $l->utm_term] : null;

        return [
            'id' => $l->id, 'displayId' => self::displayId($l->id), 'name' => $l->full_name, 'email' => $l->email, 'phone' => $l->phone,
            'campaignId' => $l->campaign_id, 'campaignName' => $l->campaign_name ?? null, 'deploymentId' => $l->deployment_id, 'pageName' => $l->page_name ?? null,
            'deploymentDeleted' => (bool) $l->deployment_deleted, 'sourceSystem' => $l->source_system, 'sourceIdentifier' => $l->source_identifier, 'vip' => (bool) $l->vip,
            'utm' => $utm && array_filter($utm) ? $utm : null, 'utmLocked' => ! $utmOn,
            'receivedAt' => Fmt::iso($l->received_at), 'status' => $l->status, 'assignmentPath' => $l->assignment_path, 'assigneeId' => $l->assignee_id, 'assigneeName' => $l->assignee_name ?? null,
            'assignedAt' => Fmt::iso($l->assigned_at), 'claimedAt' => Fmt::iso($l->claimed_at), 'reassignedAt' => Fmt::iso($l->reassigned_at), 'respondedAt' => Fmt::iso($l->responded_at), 'respondedBy' => $l->responded_by,
            'respondedByName' => $l->responded_by_name ?? null, 'hasExternalLifecycleEvents' => (bool) $l->has_external_lifecycle_events,
            'thresholdMinutes' => $thr, 'isOverdue' => Rules::isOverdue($l, $thr, $now),
            'customerWaitingMs' => Rules::customerWaitingMs($l, $now), 'responseTimeMs' => Rules::responseTimeMs($l, $now),
            'canRespond' => $viewer ? Rules::canRespond($l, $viewer['id'], $viewer['role']) : false,
        ];
    }

    private static function select(): Builder
    {
        return DB::table('leads')->leftJoin('campaigns', 'campaigns.id', '=', 'leads.campaign_id')->leftJoin('deployments', 'deployments.id', '=', 'leads.deployment_id')
            ->select('leads.*', 'campaigns.name as campaign_name', 'deployments.name as page_name')
            ->selectRaw('(select name from users u where u.id = leads.assignee_id) as assignee_name, (select name from users u where u.id = leads.responded_by) as responded_by_name');
    }

    /** Members see unassigned leads and their own; owner/admin see everything. */
    private static function visible(Ctx $ctx): \Closure
    {
        return function (Builder $q) use ($ctx) {
            if ($ctx->role === 'member') {
                $q->where(fn ($w) => $w->whereNull('leads.assignee_id')->orWhere('leads.assignee_id', $ctx->user->id));
            }
        };
    }

    public static function overdueSql(object $t): array
    {
        $thr = (int) $t->sla_threshold_minutes;
        $vip = $t->vip_lead_enabled ? (int) $t->vip_sla_threshold_minutes : $thr;

        return ["leads.status = 'not_responded' and leads.received_at < now() - (case when leads.vip then ?::int else ?::int end) * interval '1 minute'", [$vip, $thr]];
    }

    private static function viewer(Ctx $ctx): array
    {
        return ['id' => $ctx->user->id, 'role' => $ctx->role];
    }

    public static function list(Ctx $ctx, array $f): array
    {
        $limit = min((int) ($f['limit'] ?? 50), 200);
        $base = function (Builder $q) use ($ctx, $f) {
            $q->where('leads.tenant_id', $ctx->tenantId);
            (self::visible($ctx))($q);
            if (! empty($f['campaignId'])) {
                $q->where('leads.campaign_id', $f['campaignId']);
            }
            if (! empty($f['deploymentId'])) {
                $q->where('leads.deployment_id', $f['deploymentId']);
            }
            $a = $f['assigneeId'] ?? null;
            if ($a === 'me') {
                $q->where('leads.assignee_id', $ctx->user->id);
            } elseif ($a === 'none') {
                $q->whereNull('leads.assignee_id');
            } elseif ($a) {
                if (! Str::isUuid($a)) {
                    $q->whereRaw('false');
                } else {
                    $q->where('leads.assignee_id', $a);
                }
            }
            if (! empty($f['q'])) {
                $like = '%'.$f['q'].'%';
                $q->where(fn ($w) => $w->where('leads.full_name', 'ilike', $like)->orWhere('leads.email', 'ilike', $like));
            }
        };
        [$od, $odB] = self::overdueSql($ctx->tenant);
        $q = self::select();
        $base($q);
        match ($f['status'] ?? 'all') {
            'responded' => $q->where('leads.status', 'responded'),
            'not_responded' => $q->where('leads.status', 'not_responded'),
            'unassigned' => $q->where('leads.status', 'not_responded')->whereNull('leads.assignee_id'),
            'overdue' => $q->whereRaw($od, $odB),
            default => null,
        };
        if (! empty($f['cursor'])) {
            try {
                $q->where('leads.received_at', '<', (new \DateTimeImmutable($f['cursor']))->format('Y-m-d H:i:s.uP'));
            } catch (\Throwable) {
            }
        }
        $rows = $q->orderByDesc('leads.received_at')->limit($limit + 1)->get();
        $page = $rows->slice(0, $limit)->values();
        $sq = DB::table('leads');
        $base($sq);
        $sum = $sq->selectRaw("count(*) as total, count(*) filter (where leads.status = 'responded') as responded, count(*) filter (where {$od}) as overdue,
            avg(extract(epoch from (leads.responded_at - leads.received_at))) filter (where leads.responded_at is not null) as avg", $odB)->first();
        $v = self::viewer($ctx);

        return [
            'leads' => $page->map(fn ($l) => self::serialize($l, $ctx->tenant, $v))->all(),
            'total' => (int) $sum->total, 'responded' => (int) $sum->responded, 'not_responded' => $sum->total - $sum->responded, 'overdue' => (int) $sum->overdue,
            'avg_response_time_seconds' => $sum->avg === null ? null : (int) round((float) $sum->avg),
            'has_more' => $rows->count() > $limit, 'next_cursor' => $rows->count() > $limit ? Fmt::iso($page->last()->received_at) : null,
        ];
    }

    public static function load(Ctx $ctx, string $id): object
    {
        $q = DB::table('leads')->where('leads.tenant_id', $ctx->tenantId);
        $l = Str::isUuid($id) ? $q->where('leads.id', $id)->where(self::visible($ctx))->select('leads.*')->first() : null;
        if (! $l) {
            throw Fail::notFound('Lead not found.');
        }

        return $l;
    }

    public static function get(Ctx $ctx, string $id): array
    {
        self::load($ctx, $id);

        return self::serialize(self::select()->where('leads.tenant_id', $ctx->tenantId)->where('leads.id', $id)->first(), $ctx->tenant, self::viewer($ctx));
    }

    public static function addLifecycle(string $tenantId, string $leadId, string $event, string $source, mixed $at = null, bool $milestone = false): void
    {
        DB::table('lead_lifecycle_events')->insert(['tenant_id' => $tenantId, 'lead_id' => $leadId, 'event' => $event, 'source' => $source, 'event_timestamp' => $at ?? now(), 'is_milestone' => $milestone]);
    }

    /** Respond = claim + acknowledge in one action. Irreversible: both timers freeze at responded_at. */
    public static function respond(Ctx $ctx, string $id): array
    {
        $l = self::load($ctx, $id);
        if ($l->status === 'responded') {
            throw Fail::conflict('This lead has already been responded to.', 'already_responded');
        }
        if (! Rules::canRespond($l, $ctx->user->id, $ctx->role)) {
            throw Fail::forbidden('This lead is assigned to someone else.');
        }
        $updated = self::markResponded($l, $ctx->user->id);
        if (! $updated) {
            throw Fail::conflict('This lead has already been responded to.', 'already_responded');
        }
        self::afterRespond($ctx->tenant, $updated, $ctx->user);

        return self::get($ctx, $id);
    }

    /** Atomic NOT_RESPONDED → RESPONDED (claiming an unowned lead on path A). Returns the row, or null if already responded. */
    private static function markResponded(object $l, string $userId): ?object
    {
        $claim = ! $l->assignee_id;
        $sql = 'update leads set status = \'responded\', responded_at = now(), responded_by = ?'
            .($claim ? ', assignee_id = ?, assignment_path = \'A\', claimed_at = now()' : '')
            .' where id = ? and status = \'not_responded\' returning *';
        $row = DB::selectOne($sql, $claim ? [$userId, $userId, $l->id] : [$userId, $l->id]);
        if ($row && $claim) {
            DB::table('lead_assignment_history')->insert(['tenant_id' => $l->tenant_id, 'lead_id' => $l->id, 'to_assignee_id' => $userId, 'path' => 'A', 'actioned_by' => $userId, 'actioned_at' => $row->responded_at]);
        }

        return $row;
    }

    /** Shared by dashboard respond, magic-link acknowledge and Telegram acknowledge. */
    public static function afterRespond(object $tenant, object $l, object $user): void
    {
        $tid = $tenant->id;
        $waited = Rules::customerWaitingMs($l);
        self::addLifecycle($tid, $l->id, "Responded — {$user->name}", 'camplo', $l->responded_at);
        if ($l->campaign_id) {
            Effects::logCampaign($tid, $l->campaign_id, $user->id, "Responded to {$l->full_name} in ".Rules::formatDuration($waited));
        }
        Effects::remember($tid, ['type' => 'lead_responded', 'campaignId' => $l->campaign_id, 'entityId' => $l->id,
            'content' => "{$user->name} responded to lead {$l->full_name} ".Rules::formatDuration($waited).' after arrival (path '.($l->assignment_path ?? 'A').').']);
        if ($waited < 5 * Rules::MIN && Rules::hasFeature($tenant->plan, 'win_signals')) {
            $cname = $l->campaign_id ? DB::table('campaigns')->where('id', $l->campaign_id)->value('name') : null;
            DB::table('insights')->insertOrIgnore([
                'tenant_id' => $tid, 'campaign_id' => $l->campaign_id, 'type' => 'win', 'severity' => 'green', 'category' => 'SLA', 'campaign_tag' => $cname,
                'observation' => explode(' ', $user->name)[0]." responded to {$l->full_name} in ".Rules::formatDuration($waited).' — inside the 5-minute conversion window.',
                'evidence' => trim('Leads answered inside five minutes are far more likely to be contacted and qualified. '.($l->vip ? 'This was a VIP page lead.' : '')),
                'dedupe_key' => "win:{$l->id}",
            ]);
            Effects::emit($tid, 'workspace:insights', ['campaignId' => $l->campaign_id]);
        }
        Effects::fireOutbound($tid, 'lead.responded', ['lead_id' => $l->id, 'name' => $l->full_name, 'responded_by' => $user->name, 'responded_at' => Fmt::iso($l->responded_at), 'waited_ms' => $waited]);
        Effects::emit($tid, "lead:{$l->id}:status", ['status' => 'responded', 'respondedAt' => Fmt::iso($l->responded_at)]);
        Effects::emit($tid, 'workspace:leads', ['type' => 'updated', 'leadId' => $l->id]);
        Effects::emit($tid, 'workspace:overdue-count');
        Effects::emit($tid, 'workspace:sla');
        if ($l->campaign_id) {
            Effects::emit($tid, "campaign:{$l->campaign_id}:health");
        }
    }

    /** Path B (owner assigns an unowned lead) or Path C (owner reassigns). Timer 1 never resets. */
    public static function assign(Ctx $ctx, string $id, string $assigneeId): array
    {
        if ($ctx->role !== 'owner') {
            throw Fail::forbidden('Only the workspace owner can assign leads to teammates.', 'owner_only');
        }
        $l = self::load($ctx, $id);
        if ($l->status === 'responded') {
            throw Fail::conflict('Responded leads cannot be reassigned.');
        }
        $target = DB::table('users')->where('tenant_id', $ctx->tenantId)->where('id', $assigneeId)->whereNull('removed_at')->first();
        if (! $target) {
            throw Fail::bad('That team member does not exist.');
        }
        if ($l->assignee_id === $assigneeId) {
            return self::get($ctx, $id);
        }
        $path = $l->assignee_id ? 'C' : 'B';
        DB::table('leads')->where('id', $id)->where('tenant_id', $ctx->tenantId)->update($path === 'B'
            ? ['assignee_id' => $assigneeId, 'assignment_path' => 'B', 'assigned_at' => now()]
            : ['assignee_id' => $assigneeId, 'assignment_path' => 'C', 'reassigned_at' => now(), 'reassigned_by' => $ctx->user->id]);
        DB::table('lead_assignment_history')->insert(['tenant_id' => $ctx->tenantId, 'lead_id' => $id, 'from_assignee_id' => $l->assignee_id, 'to_assignee_id' => $assigneeId, 'path' => $path, 'actioned_by' => $ctx->user->id]);
        self::addLifecycle($ctx->tenantId, $id, ($path === 'B' ? 'Assigned to ' : 'Reassigned to ').$target->name, 'camplo');
        if ($l->campaign_id) {
            Effects::logCampaign($ctx->tenantId, $l->campaign_id, $ctx->user->id, ($path === 'B' ? 'Assigned' : 'Reassigned')." {$l->full_name} to {$target->name}");
        }
        Effects::notify($ctx->tenantId, ['userId' => $assigneeId, 'kind' => 'assignment', 'description' => "{$ctx->user->name} assigned {$l->full_name} to you", 'link' => "/leads/{$id}"]);
        Effects::fireOutbound($ctx->tenantId, 'lead.assigned', ['lead_id' => $id, 'assignee' => $target->name, 'path' => $path]);
        Effects::emit($ctx->tenantId, "lead:{$id}:status", ['assigneeId' => $assigneeId, 'path' => $path]);
        Effects::emit($ctx->tenantId, 'workspace:leads', ['type' => 'updated', 'leadId' => $id]);

        return self::get($ctx, $id);
    }

    public static function lifecycle(Ctx $ctx, string $id): array
    {
        self::load($ctx, $id);

        return DB::table('lead_lifecycle_events')->where('tenant_id', $ctx->tenantId)->where('lead_id', $id)->orderBy('event_timestamp')->get()
            ->map(fn ($e) => ['id' => $e->id, 'at' => Fmt::iso($e->event_timestamp), 'event' => $e->event, 'source' => $e->source, 'isMilestone' => (bool) $e->is_milestone])->all();
    }

    public static function audit(Ctx $ctx, string $id): array
    {
        $l = self::load($ctx, $id);
        $hist = DB::table('lead_assignment_history')->join('users', 'users.id', '=', 'lead_assignment_history.to_assignee_id')
            ->where('lead_assignment_history.tenant_id', $ctx->tenantId)->where('lead_assignment_history.lead_id', $id)->orderBy('lead_assignment_history.actioned_at')
            ->get(['lead_assignment_history.*', 'users.name as to_name']);
        $names = DB::table('users')->where('tenant_id', $ctx->tenantId)->pluck('name', 'id');
        $out = [['at' => Fmt::iso($l->received_at), 'actorId' => null, 'actorName' => 'Camplo', 'description' => 'Lead received']];
        if ($hist->isEmpty() || $hist[0]->path === 'A') {
            $out[] = ['at' => Fmt::iso($l->received_at), 'actorId' => null, 'actorName' => 'Camplo', 'description' => 'Available to all team members'];
        }
        foreach ($hist as $h) {
            if ($h->path === 'A') {
                continue;
            }
            $by = $names[$h->actioned_by] ?? 'Unknown';
            $out[] = ['at' => Fmt::iso($h->actioned_at), 'actorId' => $h->actioned_by, 'actorName' => $by, 'description' => $h->path === 'B'
                ? "Assigned to {$h->to_name} — by {$by}"
                : 'Reassigned from '.($names[$h->from_assignee_id ?? ''] ?? 'Unassigned')." to {$h->to_name} by {$by}"];
        }
        if ($l->responded_at && $l->responded_by) {
            $who = $names[$l->responded_by] ?? 'Unknown';
            $out[] = ['at' => Fmt::iso($l->responded_at), 'actorId' => $l->responded_by, 'actorName' => $who, 'description' => "Responded — {$who}".($l->assignment_path === 'A' ? ' (claimed and acknowledged)' : ' (acknowledged)')];
        }

        return ['status' => $l->status, 'events' => $out];
    }

    public static function lifecycleSummary(Ctx $ctx, string $campaignId): array
    {
        CampaignService::load($ctx, $campaignId);
        $tot = DB::table('leads')->where('tenant_id', $ctx->tenantId)->where('campaign_id', $campaignId)
            ->selectRaw("count(*) as total, count(*) filter (where has_external_lifecycle_events) as external, count(*) filter (where status = 'responded') as responded")->first();
        $scope = fn () => DB::table('lead_lifecycle_events as e')->join('leads', 'leads.id', '=', 'e.lead_id')->where('e.tenant_id', $ctx->tenantId)->where('leads.campaign_id', $campaignId);
        $bySource = $scope()->groupBy('e.source')->selectRaw('e.source, count(*) as events, count(distinct e.lead_id) as leads')->get();
        $stages = $scope()->where('e.source', '<>', 'camplo')->groupBy('e.event', 'e.source')
            ->selectRaw('e.event, e.source, bool_or(e.is_milestone) as milestone, count(distinct e.lead_id) as leads')->orderByRaw('count(distinct e.lead_id) desc')->limit(12)->get();
        $last = $scope()->where('e.source', '<>', 'camplo')->max('e.event_timestamp');
        $total = (int) $tot->total;
        $external = (int) $tot->external;

        return [
            'campaign_id' => $campaignId, 'total_leads' => $total, 'responded' => (int) $tot->responded,
            'leads_with_external_events' => $external, 'external_coverage_rate' => $total ? $external / $total : null,
            'by_source' => $bySource->map(fn ($s) => ['source' => $s->source, 'events' => (int) $s->events, 'leads' => (int) $s->leads])->all(),
            'stages' => $stages->map(fn ($s) => ['event' => $s->event, 'source' => $s->source, 'is_milestone' => (bool) $s->milestone, 'leads' => (int) $s->leads])->all(),
            'milestones' => $stages->filter(fn ($s) => $s->milestone)->values()->map(fn ($s) => ['event' => $s->event, 'source' => $s->source, 'leads' => (int) $s->leads])->all(),
            'last_external_event_at' => Fmt::iso($last),
        ];
    }

    // ------------------------------------------------------------- ingestion

    private static function pick(array $o, string ...$keys): ?string
    {
        foreach ($keys as $k) {
            $v = $o[$k] ?? $o[strtolower($k)] ?? null;
            if (is_array($v) || is_object($v)) {
                continue;
            }
            if ($v !== null && trim((string) $v) !== '') {
                return mb_substr(trim((string) (is_bool($v) ? ($v ? 'true' : 'false') : $v)), 0, 255);
            }
        }

        return null;
    }

    /** Normalise the many shapes lead sources send (Tally, Typeform, GHL, Systeme.io, custom). */
    public static function normalizePayload(mixed $body): array
    {
        $o = is_array($body) ? $body : [];
        $data = $o['data'] ?? $o['contact'] ?? $o['payload'] ?? null;
        if (is_array($data)) {
            $o = [...$data, ...$o];
        }
        $fields = $o['fields'] ?? (is_array($data) ? ($data['fields'] ?? null) : null);
        if (is_array($fields)) {
            foreach ($fields as $f) {
                if (is_array($f) && ! empty($f['label'])) {
                    $o[preg_replace('/\s+/', '_', strtolower($f['label']))] = is_array($f['value'] ?? null) ? implode(', ', $f['value']) : ($f['value'] ?? null);
                }
            }
        }
        $first = self::pick($o, 'first_name', 'firstName', 'firstname');
        $last = self::pick($o, 'last_name', 'lastName', 'lastname');

        return [
            'fullName' => self::pick($o, 'full_name', 'fullName', 'name', 'your_name') ?? (implode(' ', array_filter([$first, $last])) ?: null),
            'email' => self::pick($o, 'email', 'email_address', 'your_email'),
            'phone' => self::pick($o, 'phone', 'phone_number', 'phoneNumber', 'mobile'),
            'sourceIdentifier' => self::pick($o, 'source_identifier', 'submission_id', 'submissionId', 'responseId', 'contact_id', 'id', 'eventId'),
            'utmSource' => self::pick($o, 'utm_source'), 'utmMedium' => self::pick($o, 'utm_medium'), 'utmCampaign' => self::pick($o, 'utm_campaign'),
            'utmContent' => self::pick($o, 'utm_content'), 'utmTerm' => self::pick($o, 'utm_term'),
            'sourceSystem' => self::pick($o, 'source_system', 'source'),
        ];
    }

    public static function verifySignature(string $secret, string $rawBody, ?string $header): bool
    {
        if (! $header) {
            return false;
        }

        return Crypto::safeEqual(trim(preg_replace('/^sha256=/', '', $header)), Crypto::hmacHex($secret, $rawBody));
    }

    /** Named inbound webhooks accept Camplo's signature, Tally's, Typeform's, or a ?token= for tools that cannot sign. */
    public static function verifyInbound(string $secret, string $rawBody, array $auth): bool
    {
        if (! empty($auth['camplo']) && self::verifySignature($secret, $rawBody, $auth['camplo'])) {
            return true;
        }
        $b64 = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
        if (! empty($auth['tally']) && Crypto::safeEqual(trim($auth['tally']), $b64)) {
            return true;
        }
        if (! empty($auth['typeform']) && Crypto::safeEqual(trim(preg_replace('/^sha256=/', '', $auth['typeform'])), $b64)) {
            return true;
        }

        return ! empty($auth['token']) && Crypto::safeEqual($auth['token'], $secret);
    }

    public static function createLead(object $tenant, array $in): object
    {
        $d = $in['data'];
        $row = [
            'tenant_id' => $tenant->id, 'deployment_id' => $in['deploymentId'], 'campaign_id' => $in['campaignId'],
            'full_name' => $d['fullName'] ?? $d['email'] ?? 'Unnamed lead', 'email' => $d['email'], 'phone' => $d['phone'],
            'source_system' => $d['sourceSystem'] ?? $in['sourceSystem'], 'source_identifier' => $d['sourceIdentifier'], 'vip' => $in['vip'] && $tenant->vip_lead_enabled,
        ];
        if (Rules::hasFeature($tenant->plan, 'utm')) {
            $row += ['utm_source' => $d['utmSource'], 'utm_medium' => $d['utmMedium'], 'utm_campaign' => $d['utmCampaign'], 'utm_content' => $d['utmContent'], 'utm_term' => $d['utmTerm']];
        }
        $id = DB::table('leads')->insertGetId($row);
        $lead = DB::table('leads')->where('id', $id)->first();
        self::addLifecycle($tenant->id, $id, 'Lead received', 'camplo', $lead->received_at);
        $lead = Plugins::runLeadPlugins($tenant, $lead);
        if ($lead->campaign_id) {
            Effects::logCampaign($tenant->id, $lead->campaign_id, null, "New lead received — {$lead->full_name}");
        }
        Effects::remember($tenant->id, ['type' => 'lead_arrived', 'campaignId' => $lead->campaign_id, 'entityId' => $id,
            'content' => "Lead {$lead->full_name} arrived from {$lead->source_system}".($lead->utm_source ? " (utm_source={$lead->utm_source}, utm_campaign=".($lead->utm_campaign ?? '').')' : '').($lead->vip ? ' [VIP]' : '').'.']);
        Effects::fireOutbound($tenant->id, 'lead.received', ['lead_id' => $id, 'name' => $lead->full_name, 'email' => $lead->email, 'campaign_id' => $lead->campaign_id]);
        Effects::emit($tenant->id, 'workspace:leads', ['type' => 'created', 'leadId' => $id]);
        Effects::emit($tenant->id, 'workspace:overdue-count');
        if ($lead->campaign_id) {
            Effects::emit($tenant->id, "campaign:{$lead->campaign_id}:health");
        }
        $tid = $tenant->id;
        Jobs::defer(fn () => Telegram::notifyNewLead($tid, $id));

        return $lead;
    }

    /** POST /api/v1/ingest/{tenantId}/{deploymentId} — per-page HMAC-signed webhook. */
    public static function ingestDeployment(string $tenantId, string $deploymentId, string $raw, ?string $signature, mixed $body): array
    {
        if (! Str::isUuid($tenantId) || ! Str::isUuid($deploymentId)) {
            return ['status' => 404];
        }
        $d = DB::table('deployments')->where('id', $deploymentId)->where('tenant_id', $tenantId)->first();
        $t = $d ? DB::table('tenants')->where('id', $tenantId)->first() : null;
        if (! $d || ! $t || $d->status === 'deleted') {
            return ['status' => 404];
        }
        if ($t->status === 'suspended' && $t->suspended_at && Rules::nowMs() - Rules::ms($t->suspended_at) > 7 * Rules::DAY) {
            return ['status' => 410];
        }
        if (! self::verifySignature(Crypto::decrypt($d->webhook_secret_encrypted), $raw, $signature)) {
            return ['status' => 401];
        }
        $data = self::normalizePayload($body);
        $src = DB::table('webhook_sources')->where('tenant_id', $tenantId)->where('deployment_id', $deploymentId)->first();
        $sourceSystem = $data['sourceSystem'] ?? $src->source_system ?? 'webhook';
        DB::table('webhook_sources')->upsert([[
            'tenant_id' => $tenantId, 'deployment_id' => $deploymentId, 'campaign_id' => $d->campaign_id, 'label' => $d->name, 'source_system' => $sourceSystem,
            'last_received_at' => now(), 'status' => 'operational',
        ]], ['tenant_id', 'deployment_id'], ['last_received_at', 'status', 'campaign_id']);
        Effects::emit($tenantId, "page:{$deploymentId}:webhook", ['status' => 'operational', 'lastReceivedAt' => Fmt::now()]);
        $lead = self::createLead($t, ['deploymentId' => $deploymentId, 'campaignId' => $d->campaign_id, 'sourceSystem' => $sourceSystem, 'vip' => (bool) $d->vip, 'data' => $data]);

        return ['status' => 201, 'leadId' => $lead->id];
    }

    /** POST /api/v1/hooks/{inboundId} — named inbound endpoints. */
    public static function ingestNamed(string $inboundId, string $raw, array $auth, mixed $body): array
    {
        if (! Str::isUuid($inboundId)) {
            return ['status' => 404];
        }
        $w = DB::table('inbound_webhooks')->where('id', $inboundId)->first();
        if (! $w || $w->status !== 'active') {
            return ['status' => 404];
        }
        if (! self::verifyInbound(Crypto::decrypt($w->secret_encrypted), $raw, $auth)) {
            return ['status' => 401];
        }
        DB::table('inbound_webhooks')->where('id', $inboundId)->update(['last_received_at' => now()]);
        $t = DB::table('tenants')->where('id', $w->tenant_id)->first();
        $lead = self::createLead($t, ['deploymentId' => null, 'campaignId' => $w->campaign_id, 'sourceSystem' => $w->source_label, 'vip' => false, 'data' => self::normalizePayload($body)]);

        return ['status' => 201, 'leadId' => $lead->id];
    }

    /** POST /api/v1/lifecycle/{integrationId}/{token} — external lifecycle events (CRM etc). */
    public static function ingestLifecycle(string $integrationId, string $token, array $body): array
    {
        if (! Str::isUuid($integrationId)) {
            return ['status' => 404];
        }
        $integ = DB::table('integrations')->where('id', $integrationId)->first();
        if (! $integ || ! $integ->webhook_url || ! str_ends_with($integ->webhook_url, "/{$token}")) {
            return ['status' => 404];
        }
        $externalId = self::pick($body, 'contact_id', 'person_id', 'external_contact_id', 'id');
        $email = self::pick($body, 'email');
        $event = self::pick($body, 'event', 'stage', 'type', 'status');
        if (! $event) {
            return ['status' => 422];
        }
        $leadId = null;
        if ($externalId) {
            $leadId = DB::table('lead_external_mappings')->where('integration_id', $integ->id)->where('external_contact_id', $externalId)->value('lead_id');
        }
        if (! $leadId && $email) {
            $leadId = DB::table('leads')->where('tenant_id', $integ->tenant_id)->whereRaw('lower(email) = ?', [strtolower($email)])->orderByDesc('received_at')->value('id');
            if ($leadId && $externalId) {
                DB::table('lead_external_mappings')->insert(['tenant_id' => $integ->tenant_id, 'lead_id' => $leadId, 'integration_id' => $integ->id, 'external_contact_id' => $externalId]);
            }
        }
        if (! $leadId) {
            return ['status' => 202];
        }
        $at = self::pick($body, 'occurred_at', 'timestamp');
        try {
            $at = $at ? new \DateTimeImmutable($at) : null;
        } catch (\Throwable) {
            $at = null;
        }
        DB::table('lead_lifecycle_events')->insert(['tenant_id' => $integ->tenant_id, 'lead_id' => $leadId, 'event' => $event, 'source' => $integ->provider,
            'is_milestone' => (bool) preg_match('/won|closed|booked|appointment/i', $event), 'event_timestamp' => $at ?? now()]);
        DB::table('leads')->where('id', $leadId)->update(['has_external_lifecycle_events' => true]);
        $lead = DB::table('leads')->where('id', $leadId)->first();
        Effects::remember($integ->tenant_id, ['type' => 'lifecycle_event', 'campaignId' => $lead->campaign_id, 'entityId' => $leadId, 'content' => "{$integ->provider}: lead {$lead->full_name} → {$event}"]);
        Effects::emit($integ->tenant_id, 'workspace:leads', ['type' => 'updated', 'leadId' => $leadId]);

        return ['status' => 201];
    }

    // ------------------------------------------------------------- acknowledgment links

    public static function createAckToken(string $tenantId, string $leadId, string $userId): string
    {
        $token = Crypto::randomToken();
        DB::table('one_time_tokens')->insert(['tenant_id' => $tenantId, 'purpose' => 'acknowledge', 'token_hash' => Crypto::sha256($token), 'lead_id' => $leadId, 'user_id' => $userId,
            'expires_at' => Fmt::ts((int) config('camplo.ack_link_expiry'))]);

        return config('camplo.app_url')."/api/acknowledge/{$token}";
    }

    private static function loadAck(string $token): array
    {
        $t = DB::table('one_time_tokens')->where('token_hash', Crypto::sha256($token))->where('purpose', 'acknowledge')->first();
        $l = $t && $t->lead_id && $t->user_id ? DB::table('leads')->where('id', $t->lead_id)->first() : null;
        if (! $l) {
            return ['state' => 'invalid'];
        }
        if ($t->used_at || $l->status === 'responded') {
            return ['state' => 'already', 'lead' => $l, 'token' => $t];
        }
        if (Rules::ms($t->expires_at) < Rules::nowMs()) {
            return ['state' => 'expired', 'lead' => $l, 'token' => $t];
        }

        return ['state' => 'ok', 'lead' => $l, 'token' => $t];
    }

    public static function ackPreview(string $token): array
    {
        $r = self::loadAck($token);
        if ($r['state'] === 'invalid') {
            return ['state' => 'invalid'];
        }
        $cname = $r['lead']->campaign_id ? DB::table('campaigns')->where('id', $r['lead']->campaign_id)->value('name') : null;

        return ['state' => $r['state'], 'lead' => ['name' => $r['lead']->full_name], 'campaign' => ['name' => $cname], 'timer' => ['elapsed' => Rules::customerWaitingMs($r['lead'])]];
    }

    public static function acknowledge(string $token): array
    {
        $r = self::loadAck($token);
        if ($r['state'] !== 'ok') {
            return ['state' => $r['state']];
        }
        $u = DB::table('users')->where('id', $r['token']->user_id)->first();
        $t = DB::table('tenants')->where('id', $r['lead']->tenant_id)->first();
        $updated = self::markResponded($r['lead'], $u->id);
        DB::table('one_time_tokens')->where('id', $r['token']->id)->update(['used_at' => now()]);
        if (! $updated) {
            return ['state' => 'already'];
        }
        self::afterRespond($t, $updated, $u);

        return ['state' => 'done', 'lead' => ['name' => $updated->full_name], 'elapsedMs' => Rules::customerWaitingMs($updated)];
    }

    /** Counts used by the nav strip, mobile header and badge. */
    public static function overdueCount(object $t): int
    {
        [$od, $b] = self::overdueSql($t);

        return DB::table('leads')->where('tenant_id', $t->id)->whereRaw($od, $b)->count();
    }
}
