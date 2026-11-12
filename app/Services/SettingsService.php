<?php

namespace App\Services;

use App\Support\Ctx;
use App\Support\Fail;
use App\Support\Fmt;
use Illuminate\Support\Facades\DB;

/** Workspace settings (notifications here; integrations, AI and Telegram arrive with their stages). */
final class SettingsService
{
    private static function prefs(object $t): array
    {
        return Fmt::json($t->notification_prefs) ?? [];
    }

    public static function notifications(Ctx $ctx): array
    {
        $t = $ctx->tenant;
        $p = self::prefs($t);

        return [
            'dailyDigest' => (bool) $t->daily_summary_enabled, 'dailyDigestTime' => substr($t->daily_summary_time, 0, 5),
            'slaBreachAlerts' => $p['slaBreach'] ?? null, 'slaBreachChannel' => $p['slaChannel'] ?? null,
            'earlyWarning' => $p['earlyWarning'] ?? null, 'budgetAlerts' => $p['budget'] ?? null,
            'webhookOffline' => $p['webhookOffline'] ?? null, 'notificationEmail' => $t->notification_email,
        ];
    }

    public static function patchNotifications(Ctx $ctx, array $in): array
    {
        $ctx->assertPerm('notifications.manage');
        $p = self::prefs($ctx->tenant);
        foreach (['slaBreachAlerts' => 'slaBreach', 'earlyWarning' => 'earlyWarning', 'budgetAlerts' => 'budget', 'webhookOffline' => 'webhookOffline'] as $k => $f) {
            if (array_key_exists($k, $in) && $in[$k] !== null) {
                $p[$f] = (bool) $in[$k];
            }
        }
        if (! empty($in['slaBreachChannel'])) {
            $p['slaChannel'] = $in['slaBreachChannel'];
        }
        $set = ['notification_prefs' => json_encode((object) $p), 'urgent_alerts_enabled' => (bool) ($p['slaBreach'] ?? true)];
        if (array_key_exists('dailyDigest', $in) && $in['dailyDigest'] !== null) {
            $set['daily_summary_enabled'] = (bool) $in['dailyDigest'];
        }
        if (! empty($in['dailyDigestTime'])) {
            if (! preg_match('/^\d{2}:\d{2}$/', $in['dailyDigestTime'])) {
                throw Fail::bad('Use HH:MM (UTC).');
            }
            $set['daily_summary_time'] = $in['dailyDigestTime'].':00';
        }
        if (! empty($in['notificationEmail'])) {
            $set['notification_email'] = $in['notificationEmail'];
        }
        DB::table('tenants')->where('id', $ctx->tenantId)->update($set);
        $ctx->reloadTenant();

        return self::notifications($ctx);
    }
}
