<?php

namespace App\Http\Controllers;

use App\Domain\Permissions;
use App\Services\Platform;
use App\Services\SettingsService;
use App\Services\WorkspaceService as WS;
use App\Support\Input;
use App\Support\Paginate;
use App\Support\Storage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkspaceController
{
    public function show()
    {
        return WS::get(ctx());
    }

    public function patch(Request $r)
    {
        return WS::patch(ctx(), Input::get($r, [
            'name' => 'sometimes|string|min:1', 'teamSize' => 'sometimes|string', 'hasMarketingStack' => 'sometimes|boolean',
            'stackCheckCompleted' => 'sometimes|boolean', 'setupComplete' => 'sometimes|boolean', 'notificationEmail' => 'sometimes|email',
        ]));
    }

    public function plan()
    {
        return WS::planInfo(ctx());
    }

    public function subscription()
    {
        return WS::subscription(ctx());
    }

    public function upgrade(Request $r)
    {
        return WS::upgrade(ctx(), Input::get($r, ['targetPlan' => 'required|in:starter,growth,watchtower,agency'])['targetPlan']);
    }

    public function logo(Request $r)
    {
        return WS::uploadLogo(ctx(), $r->file('file'));
    }

    public function removeLogo()
    {
        return WS::removeLogo(ctx());
    }

    // ------------------------------------------------------------- team
    public function members(Request $r)
    {
        return Paginate::page(WS::members(ctx()), $r);
    }

    public function member(string $id)
    {
        return WS::member(ctx(), $id);
    }

    public function role(Request $r, string $id)
    {
        return WS::changeRole(ctx(), $id, Input::get($r, ['role' => 'required|in:admin,member'])['role']);
    }

    public function permissions(Request $r, string $id)
    {
        $in = Input::get($r, ['permissions' => 'present|array']);

        return WS::setPermissions(ctx(), $id, $in['permissions']);
    }

    public function permissionCatalog()
    {
        return array_map(fn ($k) => ['key' => $k, 'label' => Permissions::ALL[$k]['label'], 'hint' => Permissions::ALL[$k]['hint'], 'admin' => Permissions::ALL[$k]['admin'], 'member' => Permissions::ALL[$k]['member']], array_keys(Permissions::ALL));
    }

    public function removeMember(string $id)
    {
        return WS::removeMember(ctx(), $id);
    }

    public function invitations(Request $r)
    {
        return Paginate::page(WS::invitations(ctx()), $r);
    }

    public function invite(Request $r)
    {
        $in = Input::get($r, ['email' => 'required|email', 'role' => 'nullable|in:admin,member']);

        return WS::invite(ctx(), $in['email'], $in['role'] ?? 'member');
    }

    public function resend(string $id)
    {
        return WS::resendInvitation(ctx(), $id);
    }

    public function cancel(string $id)
    {
        return WS::cancelInvitation(ctx(), $id);
    }

    // ------------------------------------------------------------- notifications, logs, search, settings
    public function logs(Request $r)
    {
        return Paginate::page(WS::workspaceLog(ctx(), 1000), $r);
    }

    public function notifications(Request $r)
    {
        $n = WS::notifications(ctx());

        return Paginate::page($n['notifications'], $r, extra: ['unreadCount' => $n['unreadCount']]);
    }

    public function readNotification(string $nid)
    {
        if ($nid !== 'all' && ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $nid)) {
            throw \App\Support\Fail::bad('Input validation failed');
        }

        return WS::markRead(ctx(), $nid);
    }

    public function search(Request $r)
    {
        return WS::search(ctx(), Input::get($r, ['q' => 'present|nullable|string|max:100'])['q'] ?? '');
    }

    public function settings()
    {
        return WS::settings(ctx());
    }

    public function patchSettings(Request $r)
    {
        $in = Input::get($r, [
            'name' => 'sometimes|string|min:1|max:255', 'notification_email' => 'sometimes|email',
            'urgent_alerts_enabled' => 'sometimes|boolean', 'daily_summary_enabled' => 'sometimes|boolean', 'daily_summary_time' => ['sometimes', 'regex:/^\d{2}:\d{2}$/'],
            'sla_threshold_minutes' => 'sometimes|integer|min:1|max:10080', 'vip_lead_enabled' => 'sometimes|boolean', 'vip_sla_threshold_minutes' => 'sometimes|integer|min:1|max:10080',
        ]);
        if (isset($in['name'])) {
            $in['name'] = trim($in['name']);
        }

        return WS::patchSettings(ctx(), $in);
    }

    public function notificationSettings()
    {
        return SettingsService::notifications(ctx());
    }

    public function patchNotificationSettings(Request $r)
    {
        return SettingsService::patchNotifications(ctx(), Input::get($r, [
            'dailyDigest' => 'sometimes|boolean', 'dailyDigestTime' => 'sometimes|string', 'slaBreachAlerts' => 'sometimes|boolean',
            'slaBreachChannel' => 'sometimes|in:email,telegram,both', 'earlyWarning' => 'sometimes|boolean', 'budgetAlerts' => 'sometimes|boolean',
            'webhookOffline' => 'sometimes|boolean', 'notificationEmail' => 'sometimes|email',
        ]));
    }

    // ------------------------------------------------------------- public
    public function publicPlatform()
    {
        return Platform::public();
    }

    public function health()
    {
        DB::table('tenants')->limit(1)->get(['id']);

        return ['ok' => true, 'database' => 'postgres', 'ai' => (bool) Platform::get()['ai']['openRouterApiKey'], 'memory' => config('camplo.hindsight_api_url') ? 'hindsight' : 'relational', 'queue' => 'inline'];
    }

    /** Workspace logos and avatars (the only stored files served from the app origin). */
    public function file(string $path)
    {
        $f = Storage::get($path);
        if (! $f) {
            return response()->json(['code' => 'NOT_FOUND', 'message' => 'Not found.'], 404);
        }

        return response($f['data'], 200, ['Content-Type' => $f['contentType'], 'Cache-Control' => 'public, max-age=86400']);
    }
}
