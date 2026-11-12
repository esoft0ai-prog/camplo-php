<?php

namespace App\Services;

use App\Support\Errors;
use Throwable;

/**
 * Background work. PHP has no long-lived worker here: one-off jobs run right after the response is sent
 * (Laravel's defer), and everything time-based (SLA alerts, digests, webhook health …) is covered by the
 * idempotent sweeps in Sweeps, run every minute by the scheduler.
 */
final class Jobs
{
    /** When true (tests), deferred work runs immediately. */
    public static bool $sync = false;

    public static function defer(callable $fn): void
    {
        $run = function () use ($fn) {
            try {
                $fn();
            } catch (Throwable $e) {
                Errors::capture($e, ['source' => 'job']);
            }
        };
        if (self::$sync || app()->runningInConsole()) {
            $run();

            return;
        }
        \Illuminate\Support\defer($run);
    }
}
