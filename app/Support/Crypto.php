<?php

namespace App\Support;

use RuntimeException;

/**
 * AES-256-GCM sealing, byte-compatible with the Node version: `<purpose>.` + base64(iv[12] | tag[16] | ciphertext).
 * Each secret family has its own key: a dedicated env secret, or an HKDF-SHA256 subkey of ENCRYPTION_KEY with the
 * info string `camplo:<purpose>`. Unprefixed values are legacy `general`.
 */
final class Crypto
{
    public const PURPOSES = ['general', 'ai', 'webhook', 'integration', 'telegram'];

    private static ?string $master = null;

    /** @var array<string,string> */
    private static array $keys = [];

    public static function reset(): void
    {
        self::$master = null;
        self::$keys = [];
    }

    private static function toKey(string $raw, string $name, bool $strict): string
    {
        if (preg_match('/^[0-9a-f]{64}$/i', $raw)) {
            return hex2bin($raw);
        }
        $b64 = base64_decode($raw, true);
        if ($b64 !== false && strlen($b64) === 32) {
            return $b64;
        }
        if ($strict) {
            throw new RuntimeException("{$name} must decode to 32 bytes");
        }

        return hash('sha256', $raw, true); // passphrase-style secret
    }

    private static function masterKey(): string
    {
        if (self::$master !== null) {
            return self::$master;
        }
        $raw = (string) config('camplo.encryption_key');
        if ($raw !== '') {
            return self::$master = self::toKey($raw, 'ENCRYPTION_KEY', true);
        }
        if (app()->environment('production')) {
            throw new RuntimeException('ENCRYPTION_KEY is required in production');
        }

        return self::$master = hash('sha256', 'camplo-dev-only-encryption-key', true);
    }

    private static function key(string $purpose): string
    {
        if (isset(self::$keys[$purpose])) {
            return self::$keys[$purpose];
        }
        $dedicated = $purpose === 'general' ? '' : (string) (config("camplo.encryption_keys.{$purpose}") ?? '');
        if ($dedicated !== '') {
            $k = self::toKey($dedicated, "{$purpose} encryption secret", false);
        } elseif ($purpose === 'general') {
            $k = self::masterKey();
        } else {
            $k = hash_hkdf('sha256', self::masterKey(), 32, "camplo:{$purpose}", '');
        }

        return self::$keys[$purpose] = $k;
    }

    public static function encrypt(string $plain, string $purpose = 'general'): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', self::key($purpose), OPENSSL_RAW_DATA, $iv, $tag, '', 16);

        return $purpose.'.'.base64_encode($iv.$tag.$ct);
    }

    public static function decrypt(string $blob): string
    {
        $dot = strpos($blob, '.');
        $prefix = $dot !== false && $dot > 0 ? substr($blob, 0, $dot) : '';
        $purpose = in_array($prefix, self::PURPOSES, true) ? $prefix : 'general';
        $buf = base64_decode($purpose === $prefix ? substr($blob, $dot + 1) : $blob, true);
        if ($buf === false || strlen($buf) < 28) {
            throw new RuntimeException('Invalid ciphertext');
        }
        $out = openssl_decrypt(substr($buf, 28), 'aes-256-gcm', self::key($purpose), OPENSSL_RAW_DATA, substr($buf, 0, 12), substr($buf, 12, 16));
        if ($out === false) {
            throw new RuntimeException('Unable to decrypt');
        }

        return $out;
    }

    public static function sha256(string $s): string
    {
        return hash('sha256', $s);
    }

    /** base64url without padding, like Node's randomBytes().toString('base64url'). */
    public static function randomToken(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function hmacHex(string $secret, string $payload): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    public static function safeEqual(string $a, string $b): bool
    {
        return strlen($a) === strlen($b) && hash_equals($a, $b);
    }

    /** Show only the last 4 characters of a secret, never the value. */
    public static function mask(?string $secret): ?string
    {
        if ($secret === null || $secret === '') {
            return null;
        }

        return str_repeat('•', 12).mb_substr($secret, -4);
    }
}
