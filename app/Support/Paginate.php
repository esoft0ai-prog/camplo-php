<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Cursor pagination (ADL P-4): ?limit=50 (max 100) &cursor=<opaque> → { data, next_cursor, has_more }.
 * Pages an already-ordered list by item key, exactly like the Node `paginate()`.
 */
final class Paginate
{
    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 100;

    public static function encode(string $k): string
    {
        return rtrim(strtr(base64_encode($k), '+/', '-_'), '=');
    }

    public static function decode(?string $c): ?string
    {
        if ($c === null || $c === '') {
            return null;
        }
        $d = base64_decode(strtr($c, '-_', '+/'), true);

        return $d === false ? null : $d;
    }

    public static function limit(Request $r): int
    {
        $l = $r->query('limit');
        if ($l === null || $l === '') {
            return self::DEFAULT_LIMIT;
        }
        if (! ctype_digit((string) $l) || (int) $l < 1 || (int) $l > self::MAX_LIMIT) {
            throw Fail::bad('limit must be between 1 and '.self::MAX_LIMIT.'.');
        }

        return (int) $l;
    }

    private static function defaultKey(array $o): string
    {
        foreach (['id', 'recommendation_id', 'integrationId', 'provider'] as $k) {
            if (isset($o[$k])) {
                return (string) $o[$k];
            }
        }

        return json_encode([$o['at'] ?? $o['createdAt'] ?? null, $o['description'] ?? null]);
    }

    /** @param array<int,array> $items */
    public static function page(array $items, Request $r, ?callable $key = null, bool $fromEnd = false, array $extra = []): array
    {
        $limit = self::limit($r);
        $key ??= fn (array $o) => self::defaultKey($o);
        $after = self::decode($r->query('cursor'));
        $items = array_values($items);
        if ($fromEnd) {
            $end = count($items);
            if ($after !== null) {
                $i = self::indexOf($items, $after, $key);
                $end = $i < 0 ? 0 : $i;
            }
            $start = max(0, $end - $limit);
            $data = array_slice($items, $start, $end - $start);

            return [...$extra, 'data' => $data, 'has_more' => $start > 0, 'next_cursor' => $start > 0 && $data ? self::encode($key($data[0])) : null];
        }
        $start = 0;
        if ($after !== null) {
            $i = self::indexOf($items, $after, $key);
            $start = $i < 0 ? count($items) : $i + 1;
        }
        $data = array_slice($items, $start, $limit);
        $more = $start + $limit < count($items);

        return [...$extra, 'data' => $data, 'has_more' => $more, 'next_cursor' => $more && $data ? self::encode($key($data[count($data) - 1])) : null];
    }

    private static function indexOf(array $items, string $k, callable $key): int
    {
        foreach ($items as $i => $t) {
            if ($key($t) === $k) {
                return $i;
            }
        }

        return -1;
    }
}
