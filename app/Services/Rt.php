<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

/** Real-time hub: channel events per tenant (workspace:leads, lead:<id>:status, campaign:<id>:health, …). */
final class Rt
{
    public static function emit(string $tenantId, string $channel, array $payload = []): void
    {
        try {
            DB::table('rt_events')->insert(['tenant_id' => $tenantId, 'channel' => $channel, 'payload' => json_encode((object) $payload)]);
        } catch (Throwable) {
            // never fail a user's action over a live-update hint
        }
    }

    public static function prune(): void
    {
        DB::table('rt_events')->where('created_at', '<', now()->subMinutes(10))->delete();
    }
}
