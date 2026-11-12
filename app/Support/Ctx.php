<?php

namespace App\Support;

use App\Domain\Permissions;
use App\Domain\Rules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The request context the Node services received: the signed-in user and tenant (always resolved fresh from the
 * database, so role changes and suspensions apply at once), plus cookies to set on the response.
 * Every query must filter on `$ctx->tenantId`.
 */
final class Ctx
{
    public ?object $user = null;

    public ?object $tenant = null;

    public string $tenantId = '';

    public string $role = '';

    public string $plan = '';

    /** @var Cookie[] cookies to set on the response */
    public array $setCookies = [];

    /** @var array<string,int> throttled last_active_at writes, per process */
    private static array $lastTouch = [];

    public function __construct(public Request $request) {}

    public function ip(): string
    {
        return (string) $this->request->ip();
    }

    public static function bearer(Request $r): ?string
    {
        $h = (string) $r->header('Authorization', '');
        if (str_starts_with($h, 'Bearer ')) {
            return substr($h, 7);
        }

        return self::cookies($r)['camplo_at'] ?? null;
    }

    /** Cookies parsed from the raw header (Laravel's own cookie handling expects encrypted cookies). */
    public static function cookies(Request $r): array
    {
        $out = [];
        foreach (explode(';', (string) $r->header('Cookie', '')) as $part) {
            $i = strpos($part, '=');
            if ($i > 0) {
                $out[trim(substr($part, 0, $i))] = rawurldecode(trim(substr($part, $i + 1)));
            }
        }

        return $out;
    }

    /** Path=/; HttpOnly; SameSite=Lax; Max-Age=N (+ Secure on https), like the Node app's cookies. */
    public static function cookie(string $name, string $value, int $maxAge): Cookie
    {
        $secure = str_starts_with(config('camplo.app_url'), 'https');

        return Cookie::create($name, $value, $maxAge > 0 ? time() + $maxAge : 1, '/', null, $secure, true, false, Cookie::SAMESITE_LAX);
    }

    public static function decodeUser(object $u): object
    {
        $u->permissions = Fmt::json($u->permissions ?? null);

        return $u;
    }

    /** Resolve and validate the caller (Bearer header or camplo_at cookie). */
    public function resolve(): void
    {
        $token = self::bearer($this->request);
        $claims = $token ? Jwt::verify($token) : null;
        if (! $claims || ! preg_match('/^[0-9a-f-]{36}$/i', $claims['sub']) || ! preg_match('/^[0-9a-f-]{36}$/i', $claims['tid'])) {
            throw Fail::unauthorized();
        }
        $user = DB::table('users')->where('id', $claims['sub'])->where('tenant_id', $claims['tid'])->whereNull('removed_at')->first();
        $tenant = $user ? DB::table('tenants')->where('id', $user->tenant_id)->first() : null;
        if (! $user || ! $tenant) {
            throw Fail::unauthorized();
        }
        if ($tenant->status === 'suspended') {
            throw Fail::forbidden('Your account has been suspended. Contact support.', 'account_suspended');
        }
        if ($tenant->status !== 'active') {
            throw Fail::forbidden('Your account is awaiting activation.', 'pending_activation');
        }
        $now = time();
        if ((self::$lastTouch[$user->id] ?? 0) < $now - 300) {
            self::$lastTouch[$user->id] = $now;
            DB::table('users')->where('id', $user->id)->update(['last_active_at' => now()]);
        }
        $this->setAuth($user, $tenant);
    }

    public function setAuth(object $user, object $tenant): void
    {
        $this->user = self::decodeUser($user);
        $this->tenant = $tenant;
        $this->tenantId = $tenant->id;
        $this->role = $user->role;
        $this->plan = $tenant->plan;
    }

    public function reloadTenant(): void
    {
        $this->tenant = DB::table('tenants')->where('id', $this->tenantId)->first();
        $this->plan = $this->tenant->plan;
    }

    public function assertRole(string ...$roles): void
    {
        if (! in_array($this->role, $roles, true)) {
            throw Fail::forbidden();
        }
    }

    /** Per-teammate permission check (owner always passes). */
    public function assertPerm(string $p): void
    {
        if (! Permissions::has($this->role, $this->user->permissions, $p)) {
            throw Fail::forbidden('You don\'t have permission to do this. Ask the workspace owner to turn on "'.Permissions::ALL[$p]['label'].'" for you.', 'permission_denied', ['permission' => $p]);
        }
    }

    public function can(string $p): bool
    {
        return Permissions::has($this->role, $this->user->permissions, $p);
    }

    public function assertFeature(string $f): void
    {
        if (! Rules::hasFeature($this->plan, $f)) {
            $need = Rules::FEATURE_PLAN[$f];
            throw Fail::planLimit('Available on '.ucfirst($need).' — upgrade to unlock.', $need);
        }
    }
}
