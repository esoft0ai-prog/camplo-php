<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/** Lead-ingestion outcomes per hour, for the Super Admin's 24-hour error rate. */
final class IngestStats
{
    public static function record(bool $ok): void
    {
        $key = 'ingest:'.gmdate('YmdH').':'.($ok ? 'ok' : 'err');
        Cache::add($key, 0, 90000);
        Cache::increment($key);
    }

    public static function errorRate24h(): float
    {
        $ok = $err = 0;
        for ($h = 0; $h < 24; $h++) {
            $p = 'ingest:'.gmdate('YmdH', time() - $h * 3600);
            $ok += (int) Cache::get("{$p}:ok", 0);
            $err += (int) Cache::get("{$p}:err", 0);
        }

        return $ok + $err ? $err / ($ok + $err) : 0.0;
    }
}
