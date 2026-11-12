<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class Effects
{
    public static function logWorkspace(string $tenantId, ?string $actorId, string $description): void
    {
        DB::table('workspace_logs')->insert(['tenant_id' => $tenantId, 'actor_id' => $actorId, 'description' => $description]);
    }

    public static function notify(string $tenantId, array $n): void
    {
        DB::table('notifications')->insert(['tenant_id' => $tenantId, 'user_id' => $n['userId'] ?? null, 'kind' => $n['kind'], 'description' => $n['description'], 'link' => $n['link'] ?? null]);
    }
}
