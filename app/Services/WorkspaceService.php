<?php

namespace App\Services;

use App\Domain\Permissions;
use App\Support\Crypto;
use App\Support\Ctx;
use App\Support\Fail;
use App\Support\Fmt;
use App\Support\Storage;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Workspace, team management, notifications, workspace log and global search. */
final class WorkspaceService
{
    private const PLAN_ORDER = ['starter', 'growth', 'watchtower', 'agency'];

    /** Limits for the client: null = unlimited. */
    public static function limitsOut(string $plan): array
    {
        return array_map(fn ($v) => $v < 0 ? null : $v, Platform::planLimits($plan));
    }

    public static function displayId(string $id): string
    {
        return 'CP'.strtoupper(substr(str_replace('-', '', $id), 0, 6));
    }

    private static function forMe(Ctx $ctx): \Closure
    {
        return fn (Builder $q) => $q->whereNull('user_id')->orWhere('user_id', $ctx->user->id);
    }

    public static function get(Ctx $ctx): array
    {
        $unread = DB::table('notifications')->where('tenant_id', $ctx->tenantId)->whereNull('read_at')->where(self::forMe($ctx))->count();

        return [
            ...AuthService::publicTenant($ctx->tenant), 'avgConversionRate' => Metrics::workspaceConversion($ctx->tenantId),
            'notifications' => ['unreadCount' => $unread], 'limits' => self::limitsOut($ctx->plan), 'price' => Platform::planPrice($ctx->plan),
            'me' => AuthService::publicUser($ctx->user),
        ];
    }

    public static function patch(Ctx $ctx, array $p): array
    {
        $ctx->assertPerm('workspace.manage');
        $set = [];
        if (! empty($p['name'])) {
            $set['business_name'] = trim($p['name']);
        }
        foreach (['teamSize' => 'team_size', 'hasMarketingStack' => 'has_marketing_stack', 'stackCheckCompleted' => 'stack_check_completed', 'setupComplete' => 'setup_complete'] as $k => $col) {
            if (array_key_exists($k, $p) && $p[$k] !== null) {
                $set[$col] = $p[$k];
            }
        }
        if (! empty($p['notificationEmail'])) {
            $set['notification_email'] = $p['notificationEmail'];
        }
        if ($set) {
            DB::table('tenants')->where('id', $ctx->tenantId)->update($set);
        }
        $ctx->reloadTenant();

        return AuthService::publicTenant($ctx->tenant);
    }

    public static function planInfo(Ctx $ctx): array
    {
        $usage = Metrics::advancedUsage($ctx->tenantId, $ctx->plan);

        return [
            'plan' => $ctx->plan, 'price' => Platform::planPrice($ctx->plan), 'limits' => self::limitsOut($ctx->plan),
            'aiUsage' => ['deepPercent' => (int) round(min(1, $usage) * 100), 'warning' => $usage >= 0.8 && $usage < 1, 'limitReached' => $usage >= 1],
        ];
    }

    // ------------------------------------------------------------- /settings

    public static function settings(Ctx $ctx): array
    {
        $ctx->assertRole('owner', 'admin');
        $t = $ctx->tenant;

        return [
            'workspace' => ['name' => $t->business_name, 'logoUrl' => $t->logo_url, 'plan' => $t->plan, 'storageUsedBytes' => (int) $t->storage_used_bytes, 'storageQuotaBytes' => (int) $t->storage_quota_bytes],
            'alerts' => [
                'notification_email' => $t->notification_email, 'urgent_alerts_enabled' => (bool) $t->urgent_alerts_enabled,
                'daily_summary_enabled' => (bool) $t->daily_summary_enabled, 'daily_summary_time' => substr($t->daily_summary_time, 0, 5),
            ],
            'sla' => ['sla_threshold_minutes' => (int) $t->sla_threshold_minutes, 'vip_lead_enabled' => (bool) $t->vip_lead_enabled, 'vip_sla_threshold_minutes' => (int) $t->vip_sla_threshold_minutes],
        ];
    }

    public static function patchSettings(Ctx $ctx, array $p): array
    {
        $ctx->assertRole('owner');
        $set = [];
        if (array_key_exists('name', $p)) {
            $set['business_name'] = trim($p['name']);
        }
        foreach (['notification_email', 'urgent_alerts_enabled', 'daily_summary_enabled', 'sla_threshold_minutes', 'vip_lead_enabled', 'vip_sla_threshold_minutes'] as $k) {
            if (array_key_exists($k, $p)) {
                $set[$k] = $p[$k];
            }
        }
        if (array_key_exists('daily_summary_time', $p)) {
            $set['daily_summary_time'] = substr($p['daily_summary_time'].':00', 0, 8);
        }
        if ($set) {
            DB::table('tenants')->where('id', $ctx->tenantId)->update($set);
            $ctx->reloadTenant();
            Effects::logWorkspace($ctx->tenantId, $ctx->user->id, 'Workspace settings updated ('.implode(', ', array_keys($p)).')');
        }

        return self::settings($ctx);
    }

    // ------------------------------------------------------------- plan & subscription

    private static function usage(Ctx $ctx): array
    {
        return [
            'campaigns' => DB::table('campaigns')->where('tenant_id', $ctx->tenantId)->where('status', '<>', 'complete')->count(),
            'deployments' => DB::table('deployments')->where('tenant_id', $ctx->tenantId)->where('status', '<>', 'deleted')->count(),
            'members' => DB::table('users')->where('tenant_id', $ctx->tenantId)->whereNull('removed_at')->count(),
        ];
    }

    /** What the workspace uses today against a plan's limits — a downgrade is refused while anything is over. */
    private static function usageOver(Ctx $ctx, string $target): array
    {
        $lim = Platform::planLimits($target);
        $u = self::usage($ctx);
        $over = [];
        $labels = ['campaigns' => 'active campaigns', 'deployments' => 'pages', 'members' => 'team members'];
        foreach ($labels as $k => $label) {
            if ($lim[$k] >= 0 && $u[$k] > $lim[$k]) {
                $over[] = "{$u[$k]} {$label} (plan allows {$lim[$k]})";
            }
        }

        return $over;
    }

    public static function subscription(Ctx $ctx): array
    {
        $ctx->assertRole('owner');
        $pf = Platform::get();
        $plans = [];
        foreach (self::PLAN_ORDER as $p) {
            $plans[] = ['plan' => $p, 'price' => $pf['pricing']['plans'][$p]['price'], 'limits' => self::limitsOut($p), 'available' => $p !== 'agency', 'blockers' => $p === $ctx->plan ? [] : self::usageOver($ctx, $p)];
        }
        $u = self::usage($ctx);
        $t = $ctx->tenant;

        return [
            'plan' => $ctx->plan, 'monthlyFee' => $t->monthly_fee_override === null ? $pf['pricing']['plans'][$ctx->plan]['price'] : $t->monthly_fee_override + 0,
            'managedByPolar' => (bool) $t->polar_subscription_id,
            'usage' => [...$u, 'storageUsedBytes' => (int) $t->storage_used_bytes, 'storageQuotaBytes' => (int) $t->storage_quota_bytes],
            'plans' => $plans,
        ];
    }

    public static function upgrade(Ctx $ctx, string $target): array
    {
        $ctx->assertRole('owner');
        if ($target === $ctx->plan) {
            throw Fail::bad("You're already on {$target}.");
        }
        if ($target === 'agency') {
            throw Fail::bad('The Agency plan arrives in v2.');
        }
        $downgrade = array_search($target, self::PLAN_ORDER, true) < array_search($ctx->plan, self::PLAN_ORDER, true);
        if ($downgrade && ($over = self::usageOver($ctx, $target))) {
            throw Fail::conflict("Before moving to {$target}, reduce: ".implode('; ', $over).'.', 'over_plan_limits');
        }
        $pf = Platform::get();
        if ($ctx->tenant->polar_subscription_id && $pf['billing']['polarAccessToken']) {
            if (! Polar::changeSubscriptionPlan($ctx->tenant->polar_subscription_id, $target)) {
                throw Fail::bad('Your payment provider could not change the subscription. Try again in a moment, or contact support.');
            }
            Effects::logWorkspace($ctx->tenantId, $ctx->user->id, "Plan change to {$target} requested (Polar applies it)");

            return ['checkoutUrl' => null, 'applied' => false, 'pending' => true];
        }
        if (! $downgrade && ($url = Polar::createCheckout($target, $ctx->tenant->owner_email, $ctx->tenantId))) {
            return ['checkoutUrl' => $url, 'applied' => false];
        }
        if ($pf['billing']['allowDirectPlanChange'] || $downgrade) {
            DB::table('tenants')->where('id', $ctx->tenantId)->update(['plan' => $target]);
            Effects::logWorkspace($ctx->tenantId, $ctx->user->id, "Plan changed from {$ctx->plan} to {$target}");

            return ['checkoutUrl' => null, 'applied' => true];
        }
        throw Fail::bad('Billing is not configured for this environment.');
    }

    // ------------------------------------------------------------- logo & avatar

    private static function image(?UploadedFile $f, array $types, string $what): array
    {
        if (! $f || ! $f->isValid()) {
            throw Fail::bad("{$what} file is missing.");
        }
        $type = $f->getClientMimeType();
        if (! in_array($type, $types, true)) {
            throw Fail::bad($what === 'Logo' ? 'Logo must be a PNG or JPG.' : 'Profile picture must be a PNG, JPG or WebP image.');
        }
        if ($f->getSize() > 2 * 1024 * 1024) {
            throw Fail::bad($what === 'Logo' ? 'Logo must be 2MB or smaller.' : 'Profile picture must be 2MB or smaller.');
        }
        $ext = str_ends_with($type, 'png') ? 'png' : (str_ends_with($type, 'webp') ? 'webp' : 'jpg');

        return [$type, $ext, (string) file_get_contents($f->getRealPath())];
    }

    public static function uploadLogo(Ctx $ctx, ?UploadedFile $f): array
    {
        $ctx->assertPerm('workspace.manage');
        [$type, $ext, $data] = self::image($f, ['image/png', 'image/jpeg', 'image/jpg'], 'Logo');
        $path = "logos/{$ctx->tenantId}/".(int) (microtime(true) * 1000).".{$ext}";
        Storage::put($path, $data, $type);
        $logoUrl = "/api/files/{$path}";
        DB::table('tenants')->where('id', $ctx->tenantId)->update(['logo_url' => $logoUrl]);

        return ['logoUrl' => $logoUrl];
    }

    public static function removeLogo(Ctx $ctx): array
    {
        $ctx->assertPerm('workspace.manage');
        DB::table('tenants')->where('id', $ctx->tenantId)->update(['logo_url' => null]);

        return ['ok' => true];
    }

    private static function freshUser(string $id): object
    {
        return Ctx::decodeUser(DB::table('users')->where('id', $id)->first());
    }

    public static function uploadAvatar(Ctx $ctx, ?UploadedFile $f): array
    {
        [$type, $ext, $data] = self::image($f, ['image/png', 'image/jpeg', 'image/jpg', 'image/webp'], 'Profile picture');
        $path = "avatars/{$ctx->tenantId}/{$ctx->user->id}-".(int) (microtime(true) * 1000).".{$ext}";
        Storage::put($path, $data, $type);
        self::dropAvatar($ctx);
        DB::table('users')->where('id', $ctx->user->id)->update(['avatar_url' => "/api/files/{$path}"]);

        return AuthService::publicUser(self::freshUser($ctx->user->id));
    }

    private static function dropAvatar(Ctx $ctx): void
    {
        $old = (string) $ctx->user->avatar_url;
        if (str_starts_with($old, '/api/files/avatars/')) {
            try {
                Storage::removePrefix(substr($old, strlen('/api/files/')));
            } catch (Throwable) {
            }
        }
    }

    public static function removeAvatar(Ctx $ctx): array
    {
        self::dropAvatar($ctx);
        DB::table('users')->where('id', $ctx->user->id)->update(['avatar_url' => null]);

        return AuthService::publicUser(self::freshUser($ctx->user->id));
    }

    public static function updateMe(Ctx $ctx, array $p): array
    {
        $set = [];
        if (! empty($p['name'])) {
            $set['name'] = trim($p['name']);
        }
        if (! empty($p['theme'])) {
            $set['theme'] = $p['theme'];
        }
        if ($set) {
            DB::table('users')->where('id', $ctx->user->id)->update($set);
        }

        return AuthService::publicUser(self::freshUser($ctx->user->id));
    }

    // ------------------------------------------------------------- team

    private static function performance(Ctx $ctx, string $userId): array
    {
        $week = Fmt::ts(-7 * 86400);
        $mine = fn (Builder $q) => $q->where('responded_by', $userId);
        $w = Metrics::avgResponseMs($ctx->tenantId, $week, null, $mine);
        $m = Metrics::avgResponseMs($ctx->tenantId, Fmt::ts(-30 * 86400), null, $mine);
        $thr = (int) $ctx->tenant->sla_threshold_minutes;
        $agg = DB::table('leads')->where('tenant_id', $ctx->tenantId)->where('assignee_id', $userId)->where('received_at', '>=', $week)
            ->selectRaw("count(*) as assigned, count(*) filter (where status = 'responded') as responded,
                count(*) filter (where coalesce(responded_at, now()) - received_at > ?::int * interval '1 minute') as breaches,
                min(extract(epoch from (responded_at - received_at)) * 1000) filter (where responded_by = ?) as fastest", [$thr, $userId])->first();

        return [
            'avgThisWeekMs' => $w['avgMs'], 'avg30dMs' => $m['avgMs'], 'respondedThisWeek' => $w['count'],
            'acknowledgmentRate' => $agg->assigned ? $agg->responded / $agg->assigned : null,
            'breachesThisWeek' => (int) $agg->breaches, 'fastestThisWeekMs' => $agg->fastest === null ? null : (int) round((float) $agg->fastest),
        ];
    }

    public static function members(Ctx $ctx): array
    {
        $rows = DB::table('users')->where('tenant_id', $ctx->tenantId)->whereNull('removed_at')->whereNotNull('joined_at')->orderBy('created_at')->get();

        return $rows->map(fn ($u) => [...AuthService::publicUser($u), 'performance' => self::performance($ctx, $u->id)])->all();
    }

    private static function memberRow(Ctx $ctx, string $id): object
    {
        $u = DB::table('users')->where('tenant_id', $ctx->tenantId)->where('id', $id)->whereNull('removed_at')->first();
        if (! $u) {
            throw Fail::notFound('Member not found.');
        }

        return Ctx::decodeUser($u);
    }

    public static function member(Ctx $ctx, string $id): array
    {
        if ($ctx->role === 'member' && $id !== $ctx->user->id) {
            throw Fail::forbidden();
        }
        $u = self::memberRow($ctx, $id);
        $camps = DB::table('campaign_members')->join('campaigns', 'campaigns.id', '=', 'campaign_members.campaign_id')
            ->where('campaign_members.tenant_id', $ctx->tenantId)->where('campaign_members.user_id', $id)
            ->select('campaigns.id', 'campaigns.name')->get()->map(fn ($r) => (array) $r)->all();
        $recent = DB::table('workspace_logs')->where('tenant_id', $ctx->tenantId)->where('actor_id', $id)->orderByDesc('created_at')->limit(10)->get(['description', 'created_at']);
        $responded = DB::table('leads')->where('tenant_id', $ctx->tenantId)->where('responded_by', $id)->orderByDesc('responded_at')->limit(10)->get(['full_name', 'responded_at']);
        $activity = [
            ...$recent->map(fn ($r) => ['description' => $r->description, 'at' => $r->created_at])->all(),
            ...$responded->map(fn ($r) => ['description' => "Responded to {$r->full_name}", 'at' => $r->responded_at])->all(),
        ];
        usort($activity, fn ($a, $b) => strtotime($b['at']) <=> strtotime($a['at']));
        $activity = array_map(fn ($a) => ['description' => $a['description'], 'at' => Fmt::iso($a['at'])], array_slice($activity, 0, 10));

        return [...AuthService::publicUser($u), 'performance' => self::performance($ctx, $id), 'campaigns' => $camps, 'recentActivity' => $activity];
    }

    public static function changeRole(Ctx $ctx, string $id, string $role): array
    {
        $ctx->assertRole('owner');
        $u = self::memberRow($ctx, $id);
        if ($u->role === 'owner') {
            throw Fail::forbidden('The owner role cannot be changed.');
        }
        DB::table('users')->where('id', $id)->update(['role' => $role]);
        Effects::logWorkspace($ctx->tenantId, $ctx->user->id, "{$u->name}'s role changed to {$role}");

        return ['ok' => true, 'role' => $role];
    }

    /** `null` for a key returns it to the role default. */
    public static function setPermissions(Ctx $ctx, string $id, array $changes): array
    {
        $ctx->assertRole('owner');
        $u = self::memberRow($ctx, $id);
        if ($u->role === 'owner') {
            throw Fail::bad('The owner always has every permission.');
        }
        $next = $u->permissions ?? [];
        $changed = [];
        foreach ($changes as $k => $v) {
            if (! isset(Permissions::ALL[$k])) {
                throw Fail::bad("Unknown permission: {$k}");
            }
            if (! is_bool($v) && $v !== null) {
                throw Fail::bad('Input validation failed');
            }
            if ($v === null) {
                unset($next[$k]);
            } else {
                $next[$k] = $v;
            }
            $changed[] = Permissions::ALL[$k]['label'].': '.($v === null ? 'role default' : ($v ? 'on' : 'off'));
        }
        DB::table('users')->where('id', $id)->update(['permissions' => $next ? json_encode($next) : null]);
        Effects::logWorkspace($ctx->tenantId, $ctx->user->id, "{$u->name}'s permissions changed — ".implode('; ', $changed));

        return AuthService::publicUser(self::freshUser($id));
    }

    /** Soft-remove: audit trails keep pointing at the user record. */
    public static function removeMember(Ctx $ctx, string $id): array
    {
        $ctx->assertRole('owner');
        $u = self::memberRow($ctx, $id);
        if ($u->role === 'owner') {
            throw Fail::forbidden('The owner cannot be removed.');
        }
        DB::transaction(function () use ($ctx, $id) {
            DB::table('users')->where('id', $id)->update(['removed_at' => now(), 'password_hash' => null, 'telegram_chat_id' => null]);
            DB::table('sessions')->where('user_id', $id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            DB::table('leads')->where('tenant_id', $ctx->tenantId)->where('assignee_id', $id)->where('status', 'not_responded')->update(['assignee_id' => null, 'assignment_path' => null]);
        });
        Effects::logWorkspace($ctx->tenantId, $ctx->user->id, "{$u->name} removed from the workspace");

        return ['ok' => true];
    }

    public static function invitations(Ctx $ctx): array
    {
        $ctx->assertPerm('team.invite');

        return DB::table('users')->where('tenant_id', $ctx->tenantId)->whereNull('joined_at')->whereNull('removed_at')->get()->map(fn ($u) => [
            'id' => $u->id, 'email' => $u->email, 'role' => $u->role, 'invitedAt' => Fmt::iso($u->created_at), 'expiresAt' => Fmt::iso($u->invitation_expires_at),
            'status' => $u->invitation_expires_at && strtotime($u->invitation_expires_at) < time() ? 'expired' : 'pending',
        ])->all();
    }

    private static function sendInvite(Ctx $ctx, object $u): string
    {
        $token = Crypto::randomToken();
        DB::table('users')->where('id', $u->id)->update(['invitation_token' => Crypto::sha256($token), 'invitation_expires_at' => Fmt::ts((int) config('camplo.invitation_expiry_days') * 86400)]);
        Mailer::send(Mailer::invite($u->email, $ctx->user->name, $ctx->tenant->business_name, config('camplo.app_url')."/#/accept-invite?token={$token}"));

        return $token;
    }

    public static function invite(Ctx $ctx, string $email, string $role): array
    {
        $ctx->assertPerm('team.invite');
        $e = strtolower(trim($email));
        $existing = DB::table('users')->where('tenant_id', $ctx->tenantId)->whereRaw('lower(email) = ?', [$e])->first();
        if ($existing && ! $existing->removed_at) {
            throw Fail::conflict($existing->joined_at ? 'This person is already a member.' : 'An invitation has already been sent to this address.', $existing->joined_at ? 'already_member' : 'pending_invite');
        }
        $n = DB::table('users')->where('tenant_id', $ctx->tenantId)->whereNull('removed_at')->count();
        $lim = Platform::planLimits($ctx->plan)['members'];
        if ($lim >= 0 && $n >= $lim) {
            throw Fail::planLimit("Your plan includes {$lim} team members. Upgrade to invite more.", 'growth');
        }
        if ($existing) {
            DB::table('users')->where('id', $existing->id)->update(['removed_at' => null, 'joined_at' => null, 'role' => $role, 'invited_by' => $ctx->user->id, 'created_at' => now()]);
            $id = $existing->id;
        } else {
            $id = DB::table('users')->insertGetId(['tenant_id' => $ctx->tenantId, 'email' => $e, 'name' => explode('@', $e)[0], 'role' => $role, 'invited_by' => $ctx->user->id]);
        }
        $u = DB::table('users')->where('id', $id)->first();
        $token = self::sendInvite($ctx, $u);
        Effects::logWorkspace($ctx->tenantId, $ctx->user->id, "Invited {$e} as {$role}");

        return ['id' => $u->id, 'email' => $u->email, ...(app()->environment('testing') ? ['token' => $token] : [])];
    }

    public static function resendInvitation(Ctx $ctx, string $id): array
    {
        $ctx->assertPerm('team.invite');
        $u = DB::table('users')->where('tenant_id', $ctx->tenantId)->where('id', $id)->whereNull('joined_at')->whereNull('removed_at')->first();
        if (! $u) {
            throw Fail::notFound('Invitation not found.');
        }
        self::sendInvite($ctx, $u);

        return ['ok' => true, 'email' => $u->email];
    }

    public static function cancelInvitation(Ctx $ctx, string $id): array
    {
        $ctx->assertPerm('team.invite');
        DB::table('users')->where('tenant_id', $ctx->tenantId)->where('id', $id)->whereNull('joined_at')->update(['removed_at' => now(), 'invitation_token' => null]);

        return ['ok' => true];
    }

    // ------------------------------------------------------------- notifications, logs, search

    public static function notifications(Ctx $ctx): array
    {
        $rows = DB::table('notifications')->where('tenant_id', $ctx->tenantId)->where(self::forMe($ctx))->orderByDesc('created_at')->limit(50)->get();

        return [
            'notifications' => $rows->map(fn ($n) => ['id' => $n->id, 'kind' => $n->kind, 'description' => $n->description, 'link' => $n->link, 'read' => (bool) $n->read_at, 'createdAt' => Fmt::iso($n->created_at)])->all(),
            'unreadCount' => $rows->whereNull('read_at')->count(),
        ];
    }

    public static function markRead(Ctx $ctx, string $id): array
    {
        $q = DB::table('notifications')->where('tenant_id', $ctx->tenantId)->where(self::forMe($ctx))->whereNull('read_at');
        if ($id !== 'all') {
            $q->where('id', $id);
        }
        $q->update(['read_at' => now()]);

        return ['ok' => true];
    }

    public static function workspaceLog(Ctx $ctx, int $limit = 100): array
    {
        $ctx->assertPerm('logs.view');

        return DB::table('workspace_logs')->leftJoin('users', 'users.id', '=', 'workspace_logs.actor_id')->where('workspace_logs.tenant_id', $ctx->tenantId)
            ->orderByDesc('workspace_logs.created_at')->limit($limit)->get(['workspace_logs.id', 'users.name', 'workspace_logs.description', 'workspace_logs.created_at'])
            ->map(fn ($r) => ['id' => $r->id, 'actorName' => $r->name ?? 'Camplo', 'description' => $r->description, 'createdAt' => Fmt::iso($r->created_at)])->all();
    }

    public static function search(Ctx $ctx, string $q): array
    {
        $term = trim($q);
        if (mb_strlen($term) < 2) {
            return ['campaigns' => [], 'leads' => [], 'pages' => []];
        }
        $like = '%'.addcslashes($term, '%_\\').'%';
        $cs = DB::table('campaigns')->where('tenant_id', $ctx->tenantId)->where('name', 'ilike', $like)->limit(5)->get(['id', 'name', 'status']);
        $lq = DB::table('leads')->leftJoin('campaigns', 'campaigns.id', '=', 'leads.campaign_id')->where('leads.tenant_id', $ctx->tenantId)
            ->where(fn ($w) => $w->where('leads.full_name', 'ilike', $like)->orWhere('leads.email', 'ilike', $like));
        if ($ctx->role === 'member') {
            $lq->where(fn ($w) => $w->whereNull('leads.assignee_id')->orWhere('leads.assignee_id', $ctx->user->id));
        }
        $ls = $lq->limit(5)->get(['leads.id', 'leads.full_name as name', 'leads.campaign_id as campaignId', 'campaigns.name as campaignName']);
        $ps = DB::table('deployments')->where('tenant_id', $ctx->tenantId)->where('status', '<>', 'deleted')
            ->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('subdomain', 'ilike', $like))->limit(5)->get(['id', 'name', 'subdomain']);
        $base = config('camplo.pages_base_domain');

        return [
            'campaigns' => $cs->map(fn ($c) => (array) $c)->all(),
            'leads' => $ls->map(fn ($l) => [...(array) $l, 'displayId' => self::displayId($l->id)])->all(),
            'pages' => $ps->map(fn ($p) => [...(array) $p, 'host' => "{$p->subdomain}.{$base}"])->all(),
        ];
    }

    public static function teamAvatars(Ctx $ctx): array
    {
        return DB::table('users')->where('tenant_id', $ctx->tenantId)->whereNull('removed_at')->whereNotNull('joined_at')->get(['id', 'name'])
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'initials' => Fmt::initials($r->name)])->all();
    }
}
