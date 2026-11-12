<?php

namespace App\Services;

use App\Domain\Rules;
use App\Support\Crypto;
use App\Support\Fail;
use App\Support\Fmt;
use App\Support\IngestStats;
use App\Support\Jwt;
use Illuminate\Support\Facades\DB;

/**
 * Super Admin. Super admins are not tenant users: they sign in with email + password + TOTP and receive a
 * short-lived admin token. They never see lead or deployment content, nor AI keys.
 */
final class AdminService
{
    // ------------------------------------------------------------------ TOTP (RFC 6238, SHA-1, 30s, 6 digits)

    private static function base32Decode(string $s): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split(strtoupper(rtrim($s, '='))) as $c) {
            $v = strpos($alphabet, $c);
            if ($v !== false) {
                $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
            }
        }
        $out = '';
        for ($i = 0; $i + 8 <= strlen($bits); $i += 8) {
            $out .= chr(bindec(substr($bits, $i, 8)));
        }

        return $out;
    }

    public static function totp(string $secretB32, ?int $atMs = null): string
    {
        $counter = intdiv($atMs ?? Rules::nowMs(), 30_000);
        $h = hash_hmac('sha1', pack('J', $counter), self::base32Decode($secretB32), true);
        $o = ord($h[19]) & 0xF;
        $n = ((ord($h[$o]) & 0x7F) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);

        return str_pad((string) ($n % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    public static function verifyTotp(string $secretB32, string $code): bool
    {
        foreach ([-1, 0, 1] as $w) {
            if (Crypto::safeEqual(self::totp($secretB32, Rules::nowMs() + $w * 30_000), trim($code))) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ auth

    /** Bootstraps the first super admin from SUPER_ADMIN_EMAIL / SUPER_ADMIN_PASSWORD / ADMIN_TOTP_SECRET. */
    public static function ensureSuperAdmin(): void
    {
        $email = (string) config('camplo.super_admin_email');
        $password = (string) config('camplo.super_admin_password');
        if ($email === '' || $password === '' || DB::table('super_admins')->where('email', $email)->exists()) {
            return;
        }
        $totp = config('camplo.admin_totp_secret');
        DB::table('super_admins')->insert(['email' => $email, 'password_hash' => AuthService::hashPassword($password), 'totp_secret_encrypted' => $totp ? Crypto::encrypt($totp) : null]);
    }

    public static function login(string $email, string $password, string $code): array
    {
        self::ensureSuperAdmin();
        $a = DB::table('super_admins')->where('email', $email)->first();
        if (! $a || ! AuthService::checkPassword($password, $a->password_hash)) {
            throw Fail::unauthorized('Incorrect credentials.');
        }
        if (! $a->totp_secret_encrypted || ! self::verifyTotp(Crypto::decrypt($a->totp_secret_encrypted), $code)) {
            throw Fail::unauthorized('Incorrect credentials.');
        }

        return ['token' => Jwt::signAdmin($a->id)];
    }

    public static function verifyToken(?string $token): string
    {
        $id = $token ? Jwt::verifyAdmin($token) : null;
        if (! $id) {
            throw Fail::unauthorized();
        }

        return $id;
    }

    // ------------------------------------------------------------------ accounts

    public static function listAccounts(): array
    {
        $counts = DB::table('leads')->groupBy('tenant_id')->selectRaw('tenant_id, count(*) as n')->pluck('n', 'tenant_id');

        return DB::table('tenants')->orderByDesc('created_at')->get()->map(fn ($t) => [
            'id' => $t->id, 'businessName' => $t->business_name, 'ownerName' => $t->owner_name, 'ownerEmail' => $t->owner_email, 'status' => $t->status, 'plan' => $t->plan,
            'monthlyFee' => Fmt::num($t->monthly_fee_override), 'createdAt' => Fmt::iso($t->created_at), 'activatedAt' => Fmt::iso($t->activated_at), 'leadCount' => (int) ($counts[$t->id] ?? 0),
        ])->all();
    }

    public static function accountDetail(string $id): array
    {
        $t = DB::table('tenants')->where('id', $id)->first();
        if (! $t) {
            throw Fail::notFound('Account not found.');
        }
        $base = collect(self::listAccounts())->firstWhere('id', $id);
        $hasKey = (bool) DB::table('ai_provider_configs')->where('tenant_id', $id)->whereNotNull('primary_api_key_encrypted')->exists();
        $history = DB::table('admin_action_log')->where('target_tenant_id', $id)->orderByDesc('created_at')->get();

        return [...$base, 'polarCustomerId' => $t->polar_customer_id, 'polarSubscriptionId' => $t->polar_subscription_id,
            'deploymentCount' => DB::table('deployments')->where('tenant_id', $id)->count(), 'leadCount' => DB::table('leads')->where('tenant_id', $id)->count(), 'aiKeyConfigured' => $hasKey,
            'storageQuotaGb' => round($t->storage_quota_bytes / 1024 ** 3 * 10) / 10, 'storageUsedMb' => (int) round($t->storage_used_bytes / 1024 ** 2),
            'notificationEmail' => $t->notification_email, 'slaThresholdMinutes' => (int) $t->sla_threshold_minutes, 'setupComplete' => (bool) $t->setup_complete,
            'history' => $history->map(fn ($h) => ['action' => $h->action, 'note' => $h->note, 'createdAt' => Fmt::iso($h->created_at)])->all()];
    }

    private static function log(string $adminId, string $action, ?string $tenantId, ?string $note = null): void
    {
        DB::table('admin_action_log')->insert(['admin_id' => $adminId, 'action' => $action, 'target_tenant_id' => $tenantId, 'note' => $note]);
    }

    public static function setStatus(string $adminId, string $id, string $status): array
    {
        $set = ['status' => $status] + match ($status) {
            'active' => ['activated_at' => now(), 'suspended_at' => null], 'suspended' => ['suspended_at' => now()], default => []
        };
        if (! DB::table('tenants')->where('id', $id)->update($set)) {
            throw Fail::notFound('Account not found.');
        }
        $t = DB::table('tenants')->where('id', $id)->first();
        self::log($adminId, ['active' => 'activate', 'suspended' => 'suspend'][$status] ?? 'flag', $id);
        if ($status === 'active') {
            // Pages taken offline by the suspension sweep come back; their files were never removed.
            DB::table('deployments')->where('tenant_id', $id)->where('status', 'deleted')->where('failure_reason', Sweeps::SUSPENDED_OFFLINE)->update(['status' => 'ready', 'failure_reason' => null]);
            Jobs::defer(fn () => Memory::ensureBank($t->id, $t->business_name));
            Mailer::send(Mailer::activated($t->owner_email));
        }

        return ['ok' => true, 'status' => $status];
    }

    public static function setPricing(string $adminId, string $id, float $fee): array
    {
        DB::table('tenants')->where('id', $id)->update(['monthly_fee_override' => number_format($fee, 2, '.', '')]);
        self::log($adminId, 'price_change', $id, 'Monthly fee set to $'.number_format($fee, 2, '.', ''));

        return ['ok' => true];
    }

    public static function addNote(string $adminId, string $id, string $note): array
    {
        self::log($adminId, 'note', $id, $note);

        return ['ok' => true];
    }

    /** Super Admin can change any account field that isn't lead or page content. */
    public static function updateAccount(string $adminId, string $id, array $p): array
    {
        $t = DB::table('tenants')->where('id', $id)->first();
        if (! $t) {
            throw Fail::notFound('Account not found.');
        }
        $set = [];
        $ch = [];
        $fee = fn ($v) => number_format((float) $v, 2, '.', '');
        if (isset($p['businessName']) && $p['businessName'] !== $t->business_name) {
            $set['business_name'] = trim($p['businessName']);
            $ch[] = "name → {$set['business_name']}";
        }
        if (isset($p['ownerName']) && $p['ownerName'] !== $t->owner_name) {
            $set['owner_name'] = trim($p['ownerName']);
            $ch[] = "owner name → {$set['owner_name']}";
        }
        if (isset($p['ownerEmail']) && mb_strtolower($p['ownerEmail']) !== $t->owner_email) {
            $email = mb_strtolower($p['ownerEmail']);
            $dup = DB::table('tenants')->where('owner_email', $email)->value('id');
            if ($dup && $dup !== $id) {
                throw Fail::conflict('Another account already uses that owner email.', 'duplicate_email');
            }
            $set['owner_email'] = $email;
            $ch[] = "owner email → {$email}";
        }
        if (isset($p['plan']) && $p['plan'] !== $t->plan) {
            $set['plan'] = $p['plan'];
            $ch[] = "plan {$t->plan} → {$p['plan']}";
        }
        if (isset($p['storageQuotaGb'])) {
            $set['storage_quota_bytes'] = (int) round($p['storageQuotaGb'] * 1024 ** 3);
            $ch[] = "storage quota → {$p['storageQuotaGb']} GB";
        }
        if (array_key_exists('monthlyFee', $p)) {
            $set['monthly_fee_override'] = $p['monthlyFee'] === null ? null : $fee($p['monthlyFee']);
            $ch[] = $p['monthlyFee'] === null ? 'fee override removed' : 'fee → $'.$fee($p['monthlyFee']);
        }
        if (isset($p['notificationEmail'])) {
            $set['notification_email'] = $p['notificationEmail'];
            $ch[] = "notification email → {$p['notificationEmail']}";
        }
        if (isset($p['slaThresholdMinutes'])) {
            $set['sla_threshold_minutes'] = $p['slaThresholdMinutes'];
            $ch[] = "SLA threshold → {$p['slaThresholdMinutes']} min";
        }
        if (isset($p['setupComplete'])) {
            $set['setup_complete'] = $p['setupComplete'];
            $ch[] = 'setup complete → '.($p['setupComplete'] ? 'true' : 'false');
        }
        if (! $ch) {
            return self::accountDetail($id);
        }
        DB::table('tenants')->where('id', $id)->update($set);
        if (isset($set['owner_email'])) {
            DB::table('users')->where('tenant_id', $id)->where('role', 'owner')->where('email', $t->owner_email)->update(['email' => $set['owner_email']]);
        }
        self::log($adminId, 'account_update', $id, implode('; ', $ch));

        return self::accountDetail($id);
    }

    public static function logPlatformChange(string $adminId, string $action, string $note): void
    {
        self::log($adminId, $action, null, $note);
    }

    public static function platformHistory(): array
    {
        return DB::table('admin_action_log')->whereNull('target_tenant_id')->orderByDesc('created_at')->limit(50)->get()
            ->map(fn ($h) => ['action' => $h->action, 'note' => $h->note, 'createdAt' => Fmt::iso($h->created_at)])->all();
    }

    /** Retrospectives are permanent; only the Super Admin can explicitly regenerate one. */
    public static function regenerateRetrospective(string $adminId, string $tenantId, string $campaignId): array
    {
        $n = DB::table('campaign_retrospectives')->where('tenant_id', $tenantId)->where('campaign_id', $campaignId)->update(['generated' => false, 'generated_at' => null, 'pdf_url' => null]);
        if (! $n) {
            throw Fail::notFound('No retrospective exists for this campaign.');
        }
        self::log($adminId, 'regenerate_retrospective', $tenantId, "Campaign {$campaignId}");
        Jobs::defer(fn () => CampaignService::generateRetrospective($tenantId, $campaignId));

        return ['ok' => true, 'generated' => false];
    }

    public static function systemHealth(): array
    {
        $today = DB::table('leads')->where('received_at', '>=', gmdate('Y-m-d').'T00:00:00Z')->count();
        $sources = DB::table('webhook_sources as w')->join('tenants as t', 't.id', '=', 'w.tenant_id')->get(['w.label', 'w.last_received_at', 'w.stale_threshold_minutes', 't.business_name']);
        $stale = $sources->filter(fn ($s) => in_array(Rules::webhookState($s->last_received_at, (int) $s->stale_threshold_minutes), ['stale', 'offline'], true));

        return [
            'leadsIngestedToday' => $today, 'ingestionErrorRate24h' => IngestStats::errorRate24h(), 'activeWebhookSources' => $sources->count() - $stale->count(),
            'staleSources' => $stale->map(fn ($s) => ['deployment_label' => $s->label, 'tenant_name' => $s->business_name, 'last_received_at' => Fmt::iso($s->last_received_at)])->values()->all(),
            'queueDepth' => 0, 'queueWarning' => false,
        ];
    }

    // ------------------------------------------------------------------ error monitoring

    public static function listErrors(string $status = 'open'): array
    {
        $q = DB::table('error_events as e')->leftJoin('tenants as t', 't.id', '=', 'e.tenant_id');
        if ($status === 'open') {
            $q->whereNull('e.resolved_at');
        } elseif ($status === 'resolved') {
            $q->whereNotNull('e.resolved_at');
        }
        $rows = $q->orderByDesc('e.last_seen_at')->limit(200)->get(['e.*', 't.business_name as business']);
        $dayAgo = Rules::nowMs() - Rules::DAY;

        return [
            'summary' => ['open' => $rows->whereNull('resolved_at')->count(), 'last24h' => $rows->filter(fn ($r) => Rules::ms($r->last_seen_at) > $dayAgo)->count(), 'sentry' => (bool) config('camplo.sentry_dsn')],
            'data' => $rows->map(fn ($r) => self::errorView($r))->all(),
        ];
    }

    private static function errorView(object $r): array
    {
        return ['id' => $r->id, 'fingerprint' => $r->fingerprint, 'source' => $r->source, 'level' => $r->level, 'message' => $r->message, 'stack' => $r->stack, 'route' => $r->route,
            'tenantId' => $r->tenant_id, 'userId' => $r->user_id, 'context' => Fmt::json($r->context), 'count' => (int) $r->count, 'firstSeenAt' => Fmt::iso($r->first_seen_at),
            'lastSeenAt' => Fmt::iso($r->last_seen_at), 'resolvedAt' => Fmt::iso($r->resolved_at), ...(property_exists($r, 'business') ? ['business' => $r->business] : [])];
    }

    public static function resolveError(string $adminId, string $id, bool $resolved = true): array
    {
        if (! DB::table('error_events')->where('id', $id)->update(['resolved_at' => $resolved ? now() : null])) {
            throw Fail::notFound('Error not found.');
        }
        $row = DB::table('error_events')->where('id', $id)->first();
        self::logPlatformChange($adminId, $resolved ? 'error_resolved' : 'error_reopened', mb_substr($row->message, 0, 200));

        return self::errorView($row);
    }

    // ------------------------------------------------------------------ platform settings

    /** Keep only keys the section knows (like the Node schemas), with the same JSON type as the default. */
    public static function cleanPlatformValues(string $section, array $values): array
    {
        $defaults = Platform::defaults()[$section] ?? [];
        $secrets = Platform::SECRETS[$section]['fields'] ?? [];
        $out = [];
        foreach ($values as $k => $v) {
            if (! array_key_exists($k, $defaults) && ! in_array($k, $secrets, true)) {
                continue;
            }
            if ($v !== null) {
                $d = $defaults[$k] ?? null;
                $ok = in_array($k, $secrets, true) ? is_string($v)
                    : match (true) {
                        is_bool($d) => is_bool($v), is_int($d) || is_float($d) => is_int($v) || is_float($v), is_string($d) => is_string($v) && mb_strlen($v) <= 2000, is_array($d) => is_array($v), default => is_scalar($v) || is_array($v)
                    };
                if (! $ok) {
                    throw Fail::bad("{$k}: invalid value");
                }
            }
            $out[$k] = $v;
        }

        return $out;
    }
}
