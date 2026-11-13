<?php

namespace App\Services;

use App\Support\Crypto;
use App\Support\Sql;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Telegram: a parallel notification + acknowledgment path that never replaces email (failed delivery falls back to
 * email). A workspace's own bot, or the Camplo-wide bot. Webhooks are authenticated with Telegram's secret_token
 * header; Acknowledge buttons carry `ack:{lead hex}:{hmac16}` signed over lead, user and tenant.
 */
final class Telegram
{
    /** @var (callable(string, string, array): array)|null test hook replacing the HTTP call */
    public static $call = null;

    public static function call(string $token, string $method, array $body): array
    {
        if (self::$call) {
            return (self::$call)($token, $method, $body);
        }
        try {
            return Http::timeout(8)->post("https://api.telegram.org/bot{$token}/{$method}", $body)->json() ?? ['ok' => false];
        } catch (Throwable $e) {
            return ['ok' => false, 'description' => $e->getMessage()];
        }
    }

    public static function verifyBot(string $token): array
    {
        $r = self::call($token, 'getMe', []);

        return ($r['ok'] ?? false) ? ['ok' => true, 'username' => $r['result']['username'] ?? null] : ['ok' => false];
    }

    private static function signingSecret(): string
    {
        return (string) (config('camplo.magic_link_signing_secret') ?: config('camplo.jwt_secret'));
    }

    private static function hex(string $uuid): string
    {
        return str_replace('-', '', $uuid);
    }

    private static function uuidOf(string $h): string
    {
        return substr($h, 0, 8).'-'.substr($h, 8, 4).'-'.substr($h, 12, 4).'-'.substr($h, 16, 4).'-'.substr($h, 20);
    }

    public static function webhookSecretFor(string $scope): string
    {
        return substr(Crypto::hmacHex(Platform::get()['telegram']['webhookSecret'] ?: (string) config('camplo.jwt_secret'), "telegram-webhook:{$scope}"), 0, 48);
    }

    public static function ackSignature(string $leadId, string $userId, string $tenantId): string
    {
        return substr(Crypto::hmacHex(self::signingSecret(), "acknowledge:{$leadId}:{$userId}:{$tenantId}"), 0, 16);
    }

    public static function ackCallbackData(string $leadId, string $userId, string $tenantId): string
    {
        return 'ack:'.self::hex($leadId).':'.self::ackSignature($leadId, $userId, $tenantId);
    }

    /** The bot a workspace sends through: its own verified bot, else the Camplo-wide bot. */
    public static function botFor(string $tenantId): ?array
    {
        $tg = DB::table('telegram_connections')->where('tenant_id', $tenantId)->where('verified', true)->first();
        if ($tg) {
            return ['token' => Crypto::decrypt($tg->bot_token_encrypted), 'scope' => $tenantId, 'criticalAlerts' => (bool) $tg->critical_alerts_enabled];
        }
        $global = Platform::get()['telegram']['botToken'];

        return $global ? ['token' => $global, 'scope' => 'global', 'criticalAlerts' => true] : null;
    }

    public static function registerWebhook(string $token, string $scope): array
    {
        $url = config('camplo.app_url').($scope === 'global' ? '/api/telegram/webhook' : "/api/telegram/{$scope}");

        return self::call($token, 'setWebhook', ['url' => $url, 'secret_token' => self::webhookSecretFor($scope), 'allowed_updates' => ['message', 'callback_query']]);
    }

    public static function linkCodeFor(string $userId): string
    {
        return self::hex($userId);
    }

    /** Handle one update for a bot scope. The caller has already checked the secret_token header. */
    public static function handleUpdate(string $scope, array $u): void
    {
        $token = $scope === 'global' ? Platform::get()['telegram']['botToken'] : (self::botFor($scope)['token'] ?? null);
        if (! $token) {
            return;
        }
        $text = $u['message']['text'] ?? null;
        if (is_string($text) && preg_match('/^\/start\s+([0-9a-f]{8,32})$/i', $text, $m)) {
            $code = strtolower($m[1]);
            $chat = $u['message']['chat']['id'];
            $q = DB::table('users')->whereNull('removed_at');
            strlen($code) === 32 ? $q->where('id', self::uuidOf($code)) : $q->whereRaw(Sql::q('id::text like ?'), [$code.'%']);
            if ($scope !== 'global') {
                $q->where('tenant_id', $scope);
            }
            $usr = $q->first();
            if ($usr) {
                DB::table('users')->where('id', $usr->id)->update(['telegram_chat_id' => (string) $chat]);
                self::call($token, 'sendMessage', ['chat_id' => $chat, 'text' => "Linked to Camplo as {$usr->name}. You'll get lead and SLA alerts here."]);
            } else {
                self::call($token, 'sendMessage', ['chat_id' => $chat, 'text' => 'That link code was not recognised. Copy it again from Camplo → Settings → Telegram.']);
            }

            return;
        }
        $cq = $u['callback_query'] ?? null;
        if (! $cq || ! preg_match('/^ack:([0-9a-f]{32}):([0-9a-f]{16})$/', (string) ($cq['data'] ?? ''), $m)) {
            return;
        }
        $leadId = self::uuidOf($m[1]);
        $l = DB::table('leads')->where('id', $leadId)->first();
        $chat = (string) ($cq['message']['chat']['id'] ?? $cq['from']['id']);
        $usr = $l && ($scope === 'global' || $scope === $l->tenant_id)
            ? DB::table('users')->where('tenant_id', $l->tenant_id)->where('telegram_chat_id', $chat)->whereNull('removed_at')->first() : null;
        $reply = 'This acknowledgment is no longer valid.';
        if ($l && $usr && Crypto::safeEqual(self::ackSignature($l->id, $usr->id, $l->tenant_id), $m[2])) {
            if ($l->status === 'responded') {
                $by = $l->responded_by ? DB::table('users')->where('id', $l->responded_by)->value('name') : null;
                $reply = 'Already acknowledged by '.($by ?? 'a teammate').' at '.gmdate('H:i', strtotime($l->responded_at)).' UTC.';
            } else {
                $claim = ! $l->assignee_id;
                $updated = Sql::updateReturning('leads', "status = 'responded', responded_at = now(), responded_by = ?".($claim ? ", assignee_id = ?, assignment_path = 'A', claimed_at = now()" : ''),
                    $claim ? [$usr->id, $usr->id] : [$usr->id], "id = ? and status = 'not_responded'", [$l->id])[0] ?? null;
                if ($updated) {
                    if ($claim) {
                        DB::table('lead_assignment_history')->insert(['tenant_id' => $l->tenant_id, 'lead_id' => $l->id, 'to_assignee_id' => $usr->id, 'path' => 'A', 'actioned_by' => $usr->id]);
                    }
                    LeadService::afterRespond(DB::table('tenants')->where('id', $l->tenant_id)->first(), $updated, $usr);
                    $reply = "✓ {$usr->name} acknowledged {$l->full_name}.";
                } else {
                    $reply = 'Already acknowledged by a teammate.';
                }
            }
        }
        self::call($token, 'answerCallbackQuery', ['callback_query_id' => $cq['id']]);
        if (isset($cq['message'])) {
            self::call($token, 'editMessageText', ['chat_id' => $cq['message']['chat']['id'], 'message_id' => $cq['message']['message_id'], 'text' => $reply]);
        }
    }

    /** Send an Acknowledge message to one user. False when Telegram is unavailable or delivery failed. */
    public static function sendLeadMessage(string $tenantId, object $user, string $leadId, string $text): bool
    {
        if (! $user->telegram_chat_id) {
            return false;
        }
        $bot = self::botFor($tenantId);
        if (! $bot || ! $bot['criticalAlerts']) {
            return false;
        }
        $r = self::call($bot['token'], 'sendMessage', ['chat_id' => $user->telegram_chat_id, 'text' => $text,
            'reply_markup' => ['inline_keyboard' => [[['text' => 'Acknowledge', 'callback_data' => self::ackCallbackData($leadId, $user->id, $tenantId)]]]]]);

        return (bool) ($r['ok'] ?? false);
    }

    /** New-lead message to every linked member (the assignee only once assigned); email fallback on failure. */
    public static function notifyNewLead(string $tenantId, string $leadId): int
    {
        $l = DB::table('leads')->where('tenant_id', $tenantId)->where('id', $leadId)->first();
        if (! $l || $l->status === 'responded') {
            return 0;
        }
        $dep = $l->deployment_id ? DB::table('deployments')->where('id', $l->deployment_id)->value('name') : null;
        $sent = 0;
        foreach (DB::table('users')->where('tenant_id', $tenantId)->whereNull('removed_at')->whereNotNull('telegram_chat_id')->where('notify_enabled', true)->get() as $u) {
            if ($l->assignee_id && $l->assignee_id !== $u->id) {
                continue;
            }
            if (self::sendLeadMessage($tenantId, $u, $l->id, "🆕 New lead: {$l->full_name}".($dep ? " ({$dep})" : '').". Tap Acknowledge once you've responded.")) {
                $sent++;
            } else {
                Mailer::send(Mailer::newLead($u->email, $l->full_name, $dep ?? 'webhook', config('camplo.app_url')."/#/leads/{$l->id}"));
            }
        }

        return $sent;
    }
}
