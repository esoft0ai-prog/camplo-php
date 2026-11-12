<?php

namespace App\Services;

use App\Domain\Rules;
use App\Support\Ctx;
use App\Support\Fail;
use App\Support\Fmt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Campaign / lead / meeting notes and Team Notes. Notes are permanent — there is deliberately no delete path.
 * Editing is allowed only by the author within the edit window (2 hours by default), enforced here.
 */
final class NoteService
{
    private static function window(): int
    {
        return (int) config('camplo.note_edit_window_seconds') * 1000;
    }

    private static function editable(mixed $createdAt, string $authorId, string $userId): bool
    {
        return Rules::noteEditable($createdAt, $authorId, $userId, self::window());
    }

    private static function until(bool $editable, mixed $createdAt): ?string
    {
        return $editable ? Fmt::isoMs(Rules::ms($createdAt) + self::window()) : null;
    }

    private static function serialize(object $n, string $userId): array
    {
        $e = self::editable($n->created_at, $n->author_id, $userId);

        return [
            'id' => $n->id, 'type' => $n->note_type, 'entityId' => $n->entity_id, 'authorId' => $n->author_id, 'authorName' => $n->author_name ?? 'Unknown', 'content' => $n->content,
            'title' => $n->title, 'meetingDate' => Fmt::iso($n->meeting_date), 'via' => $n->via, 'editedAt' => Fmt::iso($n->edited_at), 'createdAt' => Fmt::iso($n->created_at),
            'editable' => $e, 'editableUntil' => self::until($e, $n->created_at),
        ];
    }

    private static function list(Ctx $ctx, string $type, string $entityId): array
    {
        return DB::table('notes')->leftJoin('users', 'users.id', '=', 'notes.author_id')->where('notes.tenant_id', $ctx->tenantId)->where('notes.note_type', $type)->where('notes.entity_id', $entityId)
            ->orderByDesc('notes.created_at')->get(['notes.*', 'users.name as author_name'])->map(fn ($n) => self::serialize($n, $ctx->user->id))->all();
    }

    private static function create(Ctx $ctx, string $type, string $entityId, string $content, array $extra = []): array
    {
        $text = trim($content);
        if ($text === '') {
            throw Fail::bad('Note cannot be empty.');
        }
        $id = DB::table('notes')->insertGetId([
            'tenant_id' => $ctx->tenantId, 'note_type' => $type, 'entity_id' => $entityId, 'author_id' => $ctx->user->id, 'content' => $text,
            'title' => $extra['title'] ?? null, 'meeting_date' => ! empty($extra['meetingDate']) ? $extra['meetingDate'] : null, 'via' => $type === 'meeting' ? 'Manual' : null,
        ]);
        $n = DB::table('notes')->where('id', $id)->first();
        $n->author_name = $ctx->user->name;

        return self::serialize($n, $ctx->user->id);
    }

    private static function edit(Ctx $ctx, string $noteId, string $content, ?array $scope): array
    {
        $n = Str::isUuid($noteId) ? DB::table('notes')->where('tenant_id', $ctx->tenantId)->where('id', $noteId)->first() : null;
        if (! $n || ($scope && ($n->entity_id !== $scope['entityId'] || ($scope['type'] !== $n->note_type && ! ($scope['type'] === 'campaign' && $n->note_type === 'meeting'))))) {
            throw Fail::notFound('Note not found.');
        }
        if ($n->author_id !== $ctx->user->id) {
            throw Fail::forbidden('Only the author can edit a note.');
        }
        if (! self::editable($n->created_at, $n->author_id, $ctx->user->id)) {
            throw Fail::forbidden('Notes can only be edited within 2 hours of posting.', 'edit_window_closed');
        }
        if (trim($content) === '') {
            throw Fail::bad('Note cannot be empty.');
        }
        DB::table('notes')->where('id', $noteId)->update(['content' => trim($content), 'edited_at' => now()]);
        $u = DB::table('notes')->where('id', $noteId)->first();
        $u->author_name = $ctx->user->name;

        return self::serialize($u, $ctx->user->id);
    }

    public static function campaignNotes(Ctx $ctx, string $cid): array
    {
        CampaignService::load($ctx, $cid);

        return self::list($ctx, 'campaign', $cid);
    }

    public static function addCampaignNote(Ctx $ctx, string $cid, string $content): array
    {
        $c = CampaignService::load($ctx, $cid);
        $n = self::create($ctx, 'campaign', $cid, $content);
        Effects::logCampaign($ctx->tenantId, $c->id, $ctx->user->id, "{$ctx->user->name} added a note");

        return $n;
    }

    public static function editCampaignNote(Ctx $ctx, string $cid, string $noteId, string $content): array
    {
        CampaignService::load($ctx, $cid);

        return self::edit($ctx, $noteId, $content, ['type' => 'campaign', 'entityId' => $cid]);
    }

    public static function meetingNotes(Ctx $ctx, string $cid): array
    {
        CampaignService::load($ctx, $cid);

        return self::list($ctx, 'meeting', $cid);
    }

    public static function addMeetingNote(Ctx $ctx, string $cid, array $in): array
    {
        CampaignService::load($ctx, $cid);

        return self::create($ctx, 'meeting', $cid, $in['content'], $in);
    }

    public static function leadNotes(Ctx $ctx, string $leadId): array
    {
        LeadService::load($ctx, $leadId);

        return self::list($ctx, 'lead', $leadId);
    }

    public static function addLeadNote(Ctx $ctx, string $leadId, string $content): array
    {
        $l = LeadService::load($ctx, $leadId);
        $n = self::create($ctx, 'lead', $leadId, $content);
        if ($l->campaign_id) {
            Effects::logCampaign($ctx->tenantId, $l->campaign_id, $ctx->user->id, "{$ctx->user->name} added a note on {$l->full_name}");
        }

        return $n;
    }

    public static function editLeadNote(Ctx $ctx, string $leadId, string $noteId, string $content): array
    {
        LeadService::load($ctx, $leadId);

        return self::edit($ctx, $noteId, $content, ['type' => 'lead', 'entityId' => $leadId]);
    }

    public static function editAnyNote(Ctx $ctx, string $noteId, string $content): array
    {
        return self::edit($ctx, $noteId, $content, null);
    }

    // ------------------------------------------------------------- Team Notes

    private static function hydrate(Ctx $ctx, array $rows): array
    {
        if (! $rows) {
            return [];
        }
        $ids = array_map(fn ($r) => $r->id, $rows);
        $recips = DB::table('team_note_recipients')->join('users', 'users.id', '=', 'team_note_recipients.user_id')
            ->where('team_note_recipients.tenant_id', $ctx->tenantId)->whereIn('team_note_recipients.team_note_id', $ids)->get(['team_note_recipients.*', 'users.name']);
        $names = DB::table('users')->where('tenant_id', $ctx->tenantId)->pluck('name', 'id');
        $leadIds = array_values(array_filter(array_map(fn ($r) => $r->attachment_type === 'lead' ? $r->attachment_id : null, $rows)));
        $campIds = array_values(array_filter(array_map(fn ($r) => $r->attachment_type === 'campaign' ? $r->attachment_id : null, $rows)));
        $leads = $leadIds ? DB::table('leads')->whereIn('id', $leadIds)->get(['id', 'full_name', 'campaign_id'])->keyBy('id') : collect();
        $camps = $campIds ? DB::table('campaigns')->whereIn('id', $campIds)->pluck('name', 'id') : collect();
        $now = Rules::nowMs();

        return array_map(function ($t) use ($ctx, $recips, $names, $leads, $camps, $now) {
            $rs = $recips->where('team_note_id', $t->id)->values();
            $mine = $rs->firstWhere('user_id', $ctx->user->id);
            $lead = $t->attachment_type === 'lead' ? ($leads[$t->attachment_id] ?? null) : null;
            $e = self::editable($t->created_at, $t->author_id, $ctx->user->id);
            $deadline = Rules::ms($t->time_bound_deadline);

            return [
                'id' => $t->id, 'authorId' => $t->author_id, 'authorName' => $names[$t->author_id] ?? 'Unknown', 'content' => $t->content, 'createdAt' => Fmt::iso($t->created_at), 'editedAt' => Fmt::iso($t->edited_at),
                'recipients' => $rs->map(fn ($x) => ['userId' => $x->user_id, 'name' => $x->name, 'readAt' => Fmt::iso($x->read_at), 'acknowledgedAt' => Fmt::iso($x->acknowledged_at)])->all(),
                'attachment' => $t->attachment_type ? ['type' => $t->attachment_type, 'id' => $t->attachment_id, 'name' => $lead->full_name ?? ($camps[$t->attachment_id] ?? null), 'campaignId' => $lead->campaign_id ?? null] : null,
                'deadline' => Fmt::iso($t->time_bound_deadline), 'deadlineStatus' => $t->time_bound_status,
                'addressedToMe' => (bool) $mine, 'unread' => $mine && ! $mine->read_at, 'archived' => (bool) ($mine->archived_at ?? null), 'canAcknowledge' => $mine && $t->time_bound_status === 'pending',
                'approaching' => $t->time_bound_status === 'pending' && $deadline !== null && $deadline - $now < Rules::HOUR,
                'editable' => $e, 'editableUntil' => self::until($e, $t->created_at),
            ];
        }, $rows);
    }

    /** Unread+approaching → unread → read+time-bound pending → rest; newest first. */
    private static function order(array $list): array
    {
        $rank = fn ($n) => $n['unread'] && $n['approaching'] ? 0 : ($n['unread'] ? 1 : ($n['deadlineStatus'] === 'pending' ? 2 : 3));
        usort($list, fn ($a, $b) => ($rank($a) <=> $rank($b)) ?: strcmp($b['createdAt'], $a['createdAt']));

        return $list;
    }

    public static function listTeamNotes(Ctx $ctx, array $o = []): array
    {
        $ctx->assertFeature('team_notes');
        $q = DB::table('team_notes')->where('tenant_id', $ctx->tenantId);
        if (! empty($o['leadId'])) {
            $q->where('attachment_type', 'lead')->where('attachment_id', $o['leadId']);
        }
        if (! empty($o['campaignId'])) {
            $q->where('attachment_type', 'campaign')->where('attachment_id', $o['campaignId']);
        }
        if (($o['addressedTo'] ?? null) === 'me') {
            $q->whereExists(fn ($e) => $e->selectRaw('1')->from('team_note_recipients as r')->whereColumn('r.team_note_id', 'team_notes.id')->where('r.user_id', $ctx->user->id));
        }

        return self::order(self::hydrate($ctx, $q->orderByDesc('created_at')->limit(200)->get()->all()));
    }

    public static function createTeamNote(Ctx $ctx, array $in): array
    {
        $ctx->assertPerm('team_notes.post');
        $ctx->assertFeature('team_notes');
        if (trim($in['content']) === '') {
            throw Fail::bad('Note cannot be empty.');
        }
        $ids = $in['recipientIds'] ?? [];
        if (! $ids) {
            throw Fail::bad('Tag at least one team member.');
        }
        $members = DB::table('users')->where('tenant_id', $ctx->tenantId)->whereIn('id', $ids)->whereNull('removed_at')->get(['id', 'name']);
        if ($members->count() !== count(array_unique($ids))) {
            throw Fail::bad('Unknown team member.');
        }
        $type = $in['attachmentType'] ?? null;
        $att = $in['attachmentId'] ?? null;
        if ($type === 'lead' && $att) {
            LeadService::load($ctx, $att);
        }
        if ($type === 'campaign' && $att) {
            CampaignService::load($ctx, $att);
        }
        $deadline = ! empty($in['deadline']) ? new \DateTimeImmutable($in['deadline']) : null;
        if ($deadline && $deadline->getTimestamp() < time()) {
            throw Fail::bad('Deadline must be in the future.');
        }
        $id = DB::table('team_notes')->insertGetId([
            'tenant_id' => $ctx->tenantId, 'author_id' => $ctx->user->id, 'content' => trim($in['content']),
            'attachment_type' => $att ? $type : null, 'attachment_id' => $att, 'time_bound_deadline' => $deadline, 'time_bound_status' => $deadline ? 'pending' : null,
        ]);
        DB::table('team_note_recipients')->insert($members->map(fn ($m) => ['tenant_id' => $ctx->tenantId, 'team_note_id' => $id, 'user_id' => $m->id])->all());
        $n = DB::table('team_notes')->where('id', $id)->first();
        foreach ($members as $m) {
            Effects::notify($ctx->tenantId, ['userId' => $m->id, 'kind' => 'team_note', 'description' => "{$ctx->user->name} left you a note: ".mb_substr($n->content, 0, 80), 'link' => '/team-notes']);
        }
        Effects::emit($ctx->tenantId, 'workspace:team-notes');

        return self::hydrate($ctx, [$n])[0];
    }

    private static function teamNote(Ctx $ctx, string $id): object
    {
        $n = Str::isUuid($id) ? DB::table('team_notes')->where('tenant_id', $ctx->tenantId)->where('id', $id)->first() : null;
        if (! $n) {
            throw Fail::notFound('Note not found.');
        }

        return $n;
    }

    public static function editTeamNote(Ctx $ctx, string $id, string $content): array
    {
        $n = self::teamNote($ctx, $id);
        if (! self::editable($n->created_at, $n->author_id, $ctx->user->id)) {
            throw Fail::forbidden('Notes can only be edited by their author within 2 hours of posting.', 'edit_window_closed');
        }
        DB::table('team_notes')->where('id', $id)->update(['content' => trim($content), 'edited_at' => now()]);

        return self::hydrate($ctx, [DB::table('team_notes')->where('id', $id)->first()])[0];
    }

    private static function recipient(Ctx $ctx, string $id): object
    {
        $r = Str::isUuid($id) ? DB::table('team_note_recipients')->where('tenant_id', $ctx->tenantId)->where('team_note_id', $id)->where('user_id', $ctx->user->id)->first() : null;
        if (! $r) {
            throw Fail::forbidden('This note is not addressed to you.');
        }

        return $r;
    }

    public static function markRead(Ctx $ctx, string $id): array
    {
        $r = self::recipient($ctx, $id);
        if (! $r->read_at) {
            DB::table('team_note_recipients')->where('id', $r->id)->update(['read_at' => now()]);
        }

        return ['ok' => true];
    }

    public static function archive(Ctx $ctx, string $id): array
    {
        $r = self::recipient($ctx, $id);
        DB::table('team_note_recipients')->where('id', $r->id)->update(['archived_at' => now(), 'read_at' => $r->read_at ?? now()]);

        return ['ok' => true];
    }

    public static function acknowledge(Ctx $ctx, string $id): array
    {
        $r = self::recipient($ctx, $id);
        DB::table('team_note_recipients')->where('id', $r->id)->update(['acknowledged_at' => now(), 'read_at' => $r->read_at ?? now()]);
        DB::table('team_notes')->where('id', $id)->where('time_bound_status', 'pending')->update(['time_bound_status' => 'met']);
        Effects::emit($ctx->tenantId, 'workspace:team-notes');

        return ['ok' => true];
    }

    /** pending → expired once the deadline passes without acknowledgment. */
    public static function expireTeamNotes(): void
    {
        DB::table('team_notes')->where('time_bound_status', 'pending')->where('time_bound_deadline', '<', now())->update(['time_bound_status' => 'expired']);
    }
}
