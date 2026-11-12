<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/** Error monitoring hook (stage 3 stores them in error_events for the Super Admin); logs for now. */
final class Errors
{
    public static function capture(Throwable $e, array $ctx = []): void
    {
        Log::warning('['.($ctx['source'] ?? 'server').'] '.$e->getMessage(), array_diff_key($ctx, ['context' => 1]));
    }
}
