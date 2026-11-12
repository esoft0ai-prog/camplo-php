<?php

namespace App\Support;

use RuntimeException;

/** HS256 access tokens, compatible with the Node version (claims: sub, tid, role, iat, exp). */
final class Jwt
{
    private static function secret(): string
    {
        $s = (string) config('camplo.jwt_secret');
        if (app()->environment('production') && str_starts_with($s, 'dev-only')) {
            throw new RuntimeException('JWT_SECRET is required in production');
        }

        return $s;
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function unb64(string $s): string|false
    {
        return base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    public static function sign(string $sub, string $tid, string $role): string
    {
        $now = time();
        $h = self::b64(json_encode(['alg' => 'HS256']));
        $p = self::b64(json_encode(['tid' => $tid, 'role' => $role, 'sub' => $sub, 'iat' => $now, 'exp' => $now + (int) config('camplo.access_token_ttl')]));
        $sig = self::b64(hash_hmac('sha256', "{$h}.{$p}", self::secret(), true));

        return "{$h}.{$p}.{$sig}";
    }

    /** @return array{sub:string,tid:string,role:string}|null */
    public static function verify(string $token, ?int $now = null): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$h, $p, $s] = $parts;
        $header = json_decode((string) self::unb64($h), true);
        if (($header['alg'] ?? null) !== 'HS256') {
            return null;
        }
        $expected = self::b64(hash_hmac('sha256', "{$h}.{$p}", self::secret(), true));
        if (! hash_equals($expected, $s)) {
            return null;
        }
        $c = json_decode((string) self::unb64($p), true);
        if (! is_array($c) || empty($c['sub']) || ! is_string($c['tid'] ?? null)) {
            return null;
        }
        if (isset($c['exp']) && (int) $c['exp'] < ($now ?? time())) {
            return null;
        }

        return ['sub' => (string) $c['sub'], 'tid' => $c['tid'], 'role' => (string) ($c['role'] ?? '')];
    }
}
