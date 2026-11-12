<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/** JSON values exactly as the Node API produced them. */
final class Fmt
{
    /** Postgres timestamp → "2026-11-12T05:30:49.123Z" (JavaScript Date#toJSON), or null. */
    public static function iso(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $d = $v instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($v) : new DateTimeImmutable((string) $v);

        return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    /** Epoch milliseconds → ISO string with milliseconds. */
    public static function isoMs(int $ms): string
    {
        return gmdate('Y-m-d\\TH:i:s', intdiv($ms, 1000)).'.'.str_pad((string) ($ms % 1000), 3, '0', STR_PAD_LEFT).'Z';
    }

    public static function now(): string
    {
        return self::iso(new DateTimeImmutable('now'));
    }

    /** A Postgres timestamptz literal for queries ("now" ± seconds). */
    public static function ts(int $offsetSeconds = 0): string
    {
        return (new DateTimeImmutable('@'.(time() + $offsetSeconds)))->format('Y-m-d H:i:sP');
    }

    public static function bool(mixed $v): ?bool
    {
        return $v === null ? null : (bool) $v;
    }

    /** jsonb column → array (or null). */
    public static function json(mixed $v): mixed
    {
        if ($v === null) {
            return null;
        }

        return is_string($v) ? json_decode($v, true) : $v;
    }

    /** numeric column → number (drizzle returned these as strings; the client used Number()). */
    public static function num(mixed $v): int|float|null
    {
        if ($v === null) {
            return null;
        }

        return $v + 0;
    }

    public static function initials(string $name): string
    {
        $parts = array_slice(array_values(array_filter(preg_split('/\s+/', trim($name)) ?: [])), 0, 2);
        $out = implode('', array_map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)), $parts));

        return $out !== '' ? $out : '?';
    }
}
