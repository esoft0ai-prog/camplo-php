<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The few SQL fragments that differ between PostgreSQL and MySQL 8. Everything else goes through the query builder,
 * which already speaks both. MySQL connections run with time_zone '+00:00', so now() is UTC on both.
 */
final class Sql
{
    public static function mysql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    /** Number of rows matching a condition. */
    public static function count(string $cond): string
    {
        return self::mysql() ? "coalesce(sum(case when {$cond} then 1 else 0 end), 0)" : "count(*) filter (where {$cond})";
    }

    /** An aggregate (avg, min, max) over the rows matching a condition. */
    public static function aggIf(string $fn, string $expr, string $cond): string
    {
        return self::mysql() ? "{$fn}(case when {$cond} then {$expr} end)" : "{$fn}({$expr}) filter (where {$cond})";
    }

    /** Seconds from timestamp $b to timestamp $a (fractional). */
    public static function secs(string $a, string $b): string
    {
        return self::mysql() ? "(timestampdiff(microsecond, {$b}, {$a}) / 1000000)" : "extract(epoch from ({$a} - {$b}))";
    }

    /** The current time minus a number of minutes given by an SQL expression (may contain placeholders). */
    public static function minutesAgo(string $minutesExpr): string
    {
        return self::mysql() ? "(now(3) - interval ({$minutesExpr}) minute)" : "(now() - ({$minutesExpr}) * interval '1 minute')";
    }

    /** UTC calendar day of a timestamp, as YYYY-MM-DD. */
    public static function day(string $col): string
    {
        return self::mysql() ? "date_format({$col}, '%Y-%m-%d')" : "to_char({$col} at time zone 'UTC', 'YYYY-MM-DD')";
    }

    /** Saturday or Sunday (UTC). */
    public static function weekend(string $col): string
    {
        return self::mysql() ? "dayofweek({$col}) in (1, 7)" : "extract(isodow from {$col}) >= 6";
    }

    /** A uuid column compared as text (prefix searches). */
    public static function text(string $col): string
    {
        return self::mysql() ? $col : "{$col}::text";
    }

    /** Case-insensitive LIKE condition for whereRaw: [sql, bindings]. */
    public static function ilike(string $col, string $pattern): array
    {
        return ["lower({$col}) like lower(?)", [$pattern]];
    }

    /** A timestamp literal both databases accept (UTC). */
    public static function ts(DateTimeInterface|int $at): string
    {
        $d = is_int($at) ? new DateTimeImmutable('@'.$at) : DateTimeImmutable::createFromInterface($at);
        $d = $d->setTimezone(new DateTimeZone('UTC'));

        return self::mysql() ? $d->format('Y-m-d H:i:s.v') : $d->format('Y-m-d H:i:s.vP');
    }

    /** Midnight UTC of a YYYY-MM-DD day, as a timestamp literal. */
    public static function day0(string $ymd): string
    {
        return self::ts((int) strtotime("{$ymd} 00:00:00 UTC"));
    }

    /** Epoch milliseconds as a timestamp literal. */
    public static function tsMs(int $ms): string
    {
        return self::ts(DateTimeImmutable::createFromFormat('U.v', intdiv($ms, 1000).'.'.str_pad((string) ($ms % 1000), 3, '0', STR_PAD_LEFT)));
    }

    public static function uuid(): string
    {
        return (string) Str::uuid();
    }

    /** Insert a row into a uuid-keyed table and return its id (generated here, so it works on both databases). */
    public static function insertId(string $table, array $row): string
    {
        $row['id'] ??= self::uuid();
        DB::table($table)->insert($row);

        return $row['id'];
    }

    /**
     * UPDATE … RETURNING on both databases. `$set` and `$where` are SQL fragments with their own bindings.
     * On MySQL the matching rows are locked, updated and read back inside one transaction.
     *
     * @return array<int,object>
     */
    public static function updateReturning(string $table, string $set, array $setBindings, string $where, array $whereBindings, string $returning = '*'): array
    {
        if (! self::mysql()) {
            return DB::select("update {$table} set {$set} where {$where} returning {$returning}", [...$setBindings, ...$whereBindings]);
        }

        $set = self::toMysql($set);
        $where = self::toMysql($where);

        return DB::transaction(function () use ($table, $set, $setBindings, $where, $whereBindings, $returning) {
            $ids = array_column(DB::select("select id from {$table} where {$where} for update", $whereBindings), 'id');
            if (! $ids) {
                return [];
            }
            $in = implode(',', array_fill(0, count($ids), '?'));
            DB::update("update {$table} set {$set} where id in ({$in})", [...$setBindings, ...$ids]);

            return DB::select("select {$returning} from {$table} where id in ({$in})", $ids);
        });
    }

    /** Run $fn in a transaction while holding a named lock, so concurrent callers with the same key queue up. */
    public static function locked(string $key, callable $fn): mixed
    {
        if (! self::mysql()) {
            return DB::transaction(function () use ($key, $fn) {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', [$key]);

                return $fn();
            });
        }
        $name = 'camplo:'.sha1($key);
        DB::select('select get_lock(?, 10) as l', [$name]);
        try {
            return DB::transaction($fn);
        } finally {
            DB::select('select release_lock(?) as l', [$name]);
        }
    }

    /**
     * A raw SQL fragment written for PostgreSQL, translated for MySQL when needed. Understood:
     *   agg(x) filter (where c)  ·  extract(epoch from (a - b))  ·  extract(isodow from col) >= 6
     *   to_char(col at time zone 'UTC', 'YYYY-MM-DD')  ·  now() - (expr) * interval '1 minute'
     *   interval 'N unit'  ·  bool_or / bool_and  ·  ::int / ::text / ::bigint / ::jsonb casts
     */
    public static function q(string $pg): string
    {
        return self::mysql() ? self::toMysql($pg) : $pg;
    }

    public static function toMysql(string $s): string
    {
        // agg(expr) filter (where cond)
        $offset = 0;
        while (preg_match('/\b(count|avg|min|max|sum)\(/i', $s, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $fn = strtolower($m[1][0]);
            $start = $m[0][1];
            $open = $start + strlen($m[0][0]) - 1;
            $close = self::close($s, $open);
            $rest = substr($s, $close + 1);
            if (! preg_match('/^\s*filter\s*\(\s*where\s+/i', $rest, $f)) {
                $offset = $open + 1;

                continue;
            }
            $fOpen = $close + 1 + strpos($rest, '(');
            $fClose = self::close($s, $fOpen);
            $cond = substr($s, $close + 1 + strlen($f[0]), $fClose - ($close + 1 + strlen($f[0])));
            $expr = substr($s, $open + 1, $close - $open - 1);
            $rep = $fn === 'count' && trim($expr) === '*'
                ? "coalesce(sum(case when {$cond} then 1 else 0 end), 0)"
                : "{$fn}(case when {$cond} then {$expr} end)";
            $s = substr($s, 0, $start).$rep.substr($s, $fClose + 1);
            $offset = $start;
        }
        // extract(epoch from (a - b))
        while (($i = stripos($s, 'extract(epoch from (')) !== false) {
            $outerOpen = $i + strlen('extract');
            $innerOpen = $i + strlen('extract(epoch from ');
            $innerClose = self::close($s, $innerOpen);
            $outerClose = self::close($s, $outerOpen);
            $inner = substr($s, $innerOpen + 1, $innerClose - $innerOpen - 1);
            $depth = 0;
            $cut = null;
            for ($k = 0; $k < strlen($inner); $k++) {
                $c = $inner[$k];
                $depth += $c === '(' ? 1 : ($c === ')' ? -1 : 0);
                if ($depth === 0 && $c === '-' && ($inner[$k - 1] ?? '') === ' ' && ($inner[$k + 1] ?? '') === ' ') {
                    $cut = $k;
                }
            }
            if ($cut === null) {
                throw new \RuntimeException("Cannot translate: {$inner}");
            }
            $a = trim(substr($inner, 0, $cut));
            $b = trim(substr($inner, $cut + 1));
            $s = substr($s, 0, $i)."(timestampdiff(microsecond, {$b}, {$a}) / 1000000)".substr($s, $outerClose + 1);
        }
        // now() - (expr) * interval '1 minute'
        while (preg_match("/now\(\) - \(/i", $s, $m, PREG_OFFSET_CAPTURE)) {
            $open = $m[0][1] + strlen($m[0][0]) - 1;
            $close = self::close($s, $open);
            if (! preg_match("/^\s*\*\s*interval '1 minute'/i", substr($s, $close + 1), $iv)) {
                throw new \RuntimeException('Cannot translate interval arithmetic');
            }
            $expr = substr($s, $open + 1, $close - $open - 1);
            $s = substr($s, 0, $m[0][1])."(now(3) - interval ({$expr}) minute)".substr($s, $close + 1 + strlen($iv[0]));
        }
        $s = preg_replace('/\bbool_or\(/i', 'max(', $s);
        $s = preg_replace('/\bbool_and\(/i', 'min(', $s);
        $s = preg_replace('/extract\(isodow from ([\w.]+)\)\s*>=\s*6/i', 'dayofweek($1) in (1, 7)', $s);
        $s = preg_replace("/to_char\(([\w.]+) at time zone 'UTC', 'YYYY-MM-DD'\)/i", "date_format($1, '%Y-%m-%d')", $s);
        $s = preg_replace("/interval '(\d+) (minute|hour|day)s?'/i", 'interval $1 $2', $s);
        $s = preg_replace('/::(int|integer|bigint|text|jsonb|numeric)\b/i', '', $s);

        return preg_replace('/\bnow\(\)/i', 'now(3)', $s);
    }

    private static function close(string $s, int $open): int
    {
        $depth = 0;
        for ($i = $open; $i < strlen($s); $i++) {
            if ($s[$i] === '(') {
                $depth++;
            } elseif ($s[$i] === ')' && --$depth === 0) {
                return $i;
            }
        }
        throw new \RuntimeException('Unbalanced parentheses in SQL');
    }
}
