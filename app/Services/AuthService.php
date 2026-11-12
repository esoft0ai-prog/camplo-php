<?php

namespace App\Services;

use App\Domain\Permissions;
use App\Support\Crypto;
use App\Support\Ctx;
use App\Support\Fail;
use App\Support\Fmt;
use App\Support\Jwt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AuthService
{
    public const REFRESH_MAX_AGE = 30 * 24 * 3600;

    public static function hashPassword(string $p): string
    {
        return password_hash($p, PASSWORD_BCRYPT, ['cost' => 10]);
    }

    /** Verifies PHP ($2y$) and bcryptjs ($2a$ / $2b$) hashes alike. */
    public static function checkPassword(string $p, ?string $hash): bool
    {
        if (! $hash) {
            return false;
        }
        if (str_starts_with($hash, '$2a$') || str_starts_with($hash, '$2b$')) {
            $hash = '$2y$'.substr($hash, 4);
        }

        return password_verify($p, $hash);
    }

    private static function issueSession(Ctx $ctx, object $user): string
    {
        $refresh = Crypto::randomToken();
        DB::table('sessions')->insert(['tenant_id' => $user->tenant_id, 'user_id' => $user->id, 'refresh_token_hash' => Crypto::sha256($refresh)]);
        $access = Jwt::sign($user->id, $user->tenant_id, $user->role);
        $ctx->setCookies[] = Ctx::cookie('camplo_at', $access, (int) config('camplo.access_token_ttl'));
        $ctx->setCookies[] = Ctx::cookie('camplo_rt', $refresh, self::REFRESH_MAX_AGE);

        return $access;
    }

    /** Where the client should go after authenticating. */
    public static function nextRoute(object $t): string
    {
        if (in_array($t->status, ['pending_activation', 'flagged'], true)) {
            return '/pending';
        }
        if ($t->status === 'suspended') {
            return '/login';
        }

        return $t->setup_complete ? '/dashboard' : '/setup';
    }

    private static function suspended(): \App\Support\ApiException
    {
        return Fail::forbidden('Your account has been suspended. Contact support at '.Platform::get()['branding']['supportEmail'].'.', 'account_suspended');
    }

    /** 10 attempts / 15 minutes per email + IP. */
    public static function login(Ctx $ctx, string $email, string $password): array
    {
        $key = 'login:'.sha1(strtolower($email).'|'.$ctx->ip());
        $a = Cache::get($key);
        if ($a && $a['n'] >= 10 && $a['until'] > time()) {
            throw Fail::rateLimited('Too many attempts. Try again in '.(int) ceil(($a['until'] - time()) / 60).' minutes.');
        }
        $rows = DB::table('users')->join('tenants', 'tenants.id', '=', 'users.tenant_id')
            ->whereRaw('lower(users.email) = ?', [strtolower($email)])->whereNull('users.removed_at')
            ->select('users.*')->get();
        $match = null;
        foreach ($rows as $u) {
            if (self::checkPassword($password, $u->password_hash)) {
                $match = $u;
                break;
            }
        }
        if (! $match) {
            $n = ($a && $a['until'] > time() ? $a['n'] : 0) + 1;
            Cache::put($key, ['n' => $n, 'until' => time() + 900], 900);
            throw Fail::unauthorized('Incorrect email or password.');
        }
        Cache::forget($key);
        $tenant = DB::table('tenants')->where('id', $match->tenant_id)->first();
        if ($tenant->status === 'suspended') {
            throw self::suspended();
        }
        $accessToken = self::issueSession($ctx, $match);

        return ['accessToken' => $accessToken, 'next' => self::nextRoute($tenant), 'user' => self::publicUser($match), 'tenant' => self::publicTenant($tenant)];
    }

    /** Always answers ok (no account enumeration). One link per workspace the email belongs to; 5 per 15 minutes. */
    public static function requestMagicLink(string $email): array
    {
        $key = 'magic:'.sha1(strtolower($email));
        $m = Cache::get($key);
        if ($m && $m['until'] > time() && $m['n'] >= 5) {
            return ['ok' => true];
        }
        Cache::put($key, ['n' => ($m && $m['until'] > time() ? $m['n'] : 0) + 1, 'until' => time() + 900], 900);
        $rows = DB::table('users')->join('tenants', 'tenants.id', '=', 'users.tenant_id')
            ->whereRaw('lower(users.email) = ?', [strtolower($email)])->whereNull('users.removed_at')->whereNull('users.invitation_token')
            ->select('users.*')->get();
        foreach ($rows as $u) {
            $token = Crypto::randomToken();
            DB::table('one_time_tokens')->insert([
                'tenant_id' => $u->tenant_id, 'purpose' => 'magic_login', 'token_hash' => Crypto::sha256($token), 'user_id' => $u->id,
                'expires_at' => Fmt::ts((int) config('camplo.magic_link_expiry')),
            ]);
            Mailer::send(Mailer::magicLink($u->email, config('camplo.app_url')."/#/verify?token={$token}"));
        }

        return ['ok' => true];
    }

    /** A link works exactly once: used_at is checked and set in one statement. */
    public static function verifyMagicLink(Ctx $ctx, string $token): array
    {
        $msg = 'This sign-in link has expired or was already used. Request a new one.';
        $t = DB::selectOne("update one_time_tokens set used_at = now() where token_hash = ? and purpose = 'magic_login' and used_at is null and expires_at > now() returning user_id", [Crypto::sha256($token)]);
        if (! $t || ! $t->user_id) {
            throw Fail::unauthorized($msg);
        }
        $user = DB::table('users')->where('id', $t->user_id)->whereNull('removed_at')->first();
        if (! $user) {
            throw Fail::unauthorized($msg);
        }
        $tenant = DB::table('tenants')->where('id', $user->tenant_id)->first();
        if ($tenant->status === 'suspended') {
            throw self::suspended();
        }
        $accessToken = self::issueSession($ctx, $user);

        return ['accessToken' => $accessToken, 'next' => self::nextRoute($tenant), 'user' => self::publicUser($user), 'tenant' => self::publicTenant($tenant)];
    }

    public static function refresh(Ctx $ctx): array
    {
        $token = Ctx::cookies($ctx->request)['camplo_rt'] ?? null;
        if (! $token) {
            throw Fail::unauthorized();
        }
        $row = DB::table('sessions')->join('users', 'users.id', '=', 'sessions.user_id')
            ->where('sessions.refresh_token_hash', Crypto::sha256($token))->whereNull('sessions.revoked_at')
            ->where('sessions.last_used_at', '>', Fmt::ts(-(int) config('camplo.session_inactivity_days') * 86400))
            ->whereNull('users.removed_at')->select('users.*', 'sessions.id as session_id')->first();
        if (! $row) {
            throw Fail::unauthorized('Your session has expired. Please sign in again.');
        }
        DB::table('sessions')->where('id', $row->session_id)->update(['revoked_at' => now()]);

        return ['accessToken' => self::issueSession($ctx, $row)];
    }

    public static function logout(Ctx $ctx): array
    {
        $token = Ctx::cookies($ctx->request)['camplo_rt'] ?? null;
        if ($token) {
            DB::table('sessions')->where('refresh_token_hash', Crypto::sha256($token))->update(['revoked_at' => now()]);
        }
        $ctx->setCookies[] = Ctx::cookie('camplo_at', '', 0);
        $ctx->setCookies[] = Ctx::cookie('camplo_rt', '', 0);

        return ['ok' => true];
    }

    public static function signup(Ctx $ctx, array $in): array
    {
        $email = strtolower($in['email']);
        if (DB::table('tenants')->where('owner_email', $email)->exists()) {
            throw Fail::conflict('An account with this email already exists.', 'duplicate_email');
        }
        $auto = (bool) Platform::get()['signup']['autoActivate'];
        $tenant = DB::transaction(function () use ($in, $email, $auto) {
            $t = DB::selectOne('insert into tenants (business_name, owner_name, owner_email, notification_email, plan, status, activated_at, storage_quota_bytes) values (?, ?, ?, ?, ?, ?, ?, ?) returning *', [
                $in['workspaceName'], $in['name'], $email, $email, $in['plan'], $auto ? 'active' : 'pending_activation', $auto ? now() : null, config('camplo.default_storage_quota_bytes'),
            ]);
            DB::table('users')->insert(['tenant_id' => $t->id, 'email' => $email, 'name' => $in['name'], 'role' => 'owner', 'password_hash' => self::hashPassword($in['password']), 'joined_at' => now()]);
            DB::table('ai_provider_configs')->insert(['tenant_id' => $t->id]);

            return $t;
        });
        Effects::logWorkspace($tenant->id, null, "Workspace created by {$in['name']}");
        $checkoutUrl = null;
        try {
            $checkoutUrl = Polar::createCheckout($in['plan'], $email, $tenant->id);
        } catch (Throwable $e) {
            Log::error('[polar] '.$e->getMessage());
        }

        return ['next' => $auto ? '/login' : '/pending', 'checkoutUrl' => $checkoutUrl];
    }

    /** Never confirms or denies whether the account exists. */
    public static function forgotPassword(string $email): array
    {
        $rows = DB::table('users')->whereRaw('lower(email) = ?', [strtolower($email)])->whereNull('removed_at')->get();
        foreach ($rows as $u) {
            $token = Crypto::randomToken();
            DB::table('one_time_tokens')->insert([
                'tenant_id' => $u->tenant_id, 'purpose' => 'password_reset', 'token_hash' => Crypto::sha256($token), 'user_id' => $u->id,
                'expires_at' => Fmt::ts((int) config('camplo.password_reset_expiry')),
            ]);
            Mailer::send(Mailer::resetPassword($u->email, config('camplo.app_url')."/#/reset-password?token={$token}"));
        }

        return ['ok' => true];
    }

    public static function resetPassword(string $token, string $newPassword): array
    {
        if (strlen($newPassword) < 8) {
            throw Fail::bad('Password must be at least 8 characters.');
        }
        $t = DB::table('one_time_tokens')->where('token_hash', Crypto::sha256($token))->where('purpose', 'password_reset')->first();
        if (! $t || $t->used_at || strtotime($t->expires_at) < time() || ! $t->user_id) {
            throw Fail::bad('This reset link has expired or is invalid. Request a new one.');
        }
        DB::transaction(function () use ($t, $newPassword) {
            DB::table('one_time_tokens')->where('id', $t->id)->update(['used_at' => now()]);
            DB::table('users')->where('id', $t->user_id)->update(['password_hash' => self::hashPassword($newPassword)]);
            DB::table('sessions')->where('user_id', $t->user_id)->update(['revoked_at' => now()]);
        });

        return ['ok' => true];
    }

    public static function acceptInvitation(Ctx $ctx, string $token, string $password, ?string $name): array
    {
        if (strlen($password) < 8) {
            throw Fail::bad('Password must be at least 8 characters.');
        }
        $u = DB::table('users')->where('invitation_token', Crypto::sha256($token))->first();
        if (! $u || ! $u->invitation_expires_at || strtotime($u->invitation_expires_at) < time()) {
            throw Fail::bad('This invitation has expired. Ask for a new one.');
        }
        $set = ['password_hash' => self::hashPassword($password), 'invitation_token' => null, 'invitation_expires_at' => null, 'joined_at' => now()];
        if ($name) {
            $set['name'] = $name;
        }
        DB::table('users')->where('id', $u->id)->update($set);
        $updated = DB::table('users')->where('id', $u->id)->first();
        $t = DB::table('tenants')->where('id', $u->tenant_id)->first();
        Effects::logWorkspace($u->tenant_id, $u->id, "{$updated->name} joined the workspace");
        self::issueSession($ctx, $updated);

        return ['next' => self::nextRoute($t)];
    }

    public static function publicUser(object $u): array
    {
        $perms = is_array($u->permissions ?? null) ? $u->permissions : Fmt::json($u->permissions ?? null);

        return [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role, 'theme' => $u->theme, 'avatarUrl' => $u->avatar_url,
            'initials' => Fmt::initials($u->name), 'lastActiveAt' => Fmt::iso($u->last_active_at), 'joinedAt' => Fmt::iso($u->joined_at),
            'permissions' => Permissions::effective($u->role, $perms), 'permissionOverrides' => (object) ($perms ?? []),
        ];
    }

    public static function publicTenant(object $t): array
    {
        return [
            'id' => $t->id, 'name' => $t->business_name, 'status' => $t->status, 'plan' => $t->plan, 'setupComplete' => (bool) $t->setup_complete, 'logoUrl' => $t->logo_url,
            'hasMarketingStack' => Fmt::bool($t->has_marketing_stack), 'stackCheckCompleted' => (bool) $t->stack_check_completed, 'teamSize' => $t->team_size,
            'slaThresholdMinutes' => (int) $t->sla_threshold_minutes, 'vipSlaThresholdMinutes' => (int) $t->vip_sla_threshold_minutes, 'vipLeadEnabled' => (bool) $t->vip_lead_enabled,
            'storageUsedBytes' => (int) $t->storage_used_bytes, 'storageQuotaBytes' => (int) $t->storage_quota_bytes,
        ];
    }
}
