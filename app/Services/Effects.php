<?php

namespace App\Services;

use App\Support\Crypto;
use App\Support\Errors;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Side effects shared by all services: audit logs, notifications, real-time events, memory retention and outbound
 * webhooks. Nothing here throws into the caller — a failed webhook or memory write never fails a user's action.
 */
final class Effects
{
    public static function logWorkspace(string $tenantId, ?string $actorId, string $description): void
    {
        DB::table('workspace_logs')->insert(['tenant_id' => $tenantId, 'actor_id' => $actorId, 'description' => $description]);
    }

    public static function logCampaign(string $tenantId, string $campaignId, ?string $actorId, string $description): void
    {
        DB::table('campaign_logs')->insert(['tenant_id' => $tenantId, 'campaign_id' => $campaignId, 'actor_id' => $actorId, 'description' => $description]);
    }

    public static function notify(string $tenantId, array $n): void
    {
        DB::table('notifications')->insert(['tenant_id' => $tenantId, 'user_id' => $n['userId'] ?? null, 'kind' => $n['kind'], 'description' => $n['description'], 'link' => $n['link'] ?? null]);
        Rt::emit($tenantId, 'workspace:notifications');
    }

    public static function emit(string $tenantId, string $channel, array $payload = []): void
    {
        Rt::emit($tenantId, $channel, $payload);
    }

    /** Campaign memory (stage 4 wires the memory bank in; until then this is a no-op hook). */
    public static function remember(string $tenantId, array $ev): void
    {
        Jobs::defer(fn () => Memory::retain($tenantId, $ev));
    }

    /** Fire outbound webhooks for an event, after the response. Body signed with HMAC-SHA256 in X-Camplo-Signature. */
    public static function fireOutbound(string $tenantId, string $event, array $data): void
    {
        Jobs::defer(function () use ($tenantId, $event, $data) {
            $hooks = DB::table('outbound_webhooks')->where('tenant_id', $tenantId)->where('event_trigger', $event)->where('status', 'active')->get();
            foreach ($hooks as $h) {
                $body = json_encode(['event' => $event, 'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'), 'data' => $data]);
                $headers = ['Content-Type' => 'application/json', 'X-Camplo-Event' => $event];
                try {
                    if ($h->secret_encrypted) {
                        $headers['X-Camplo-Signature'] = 'sha256='.Crypto::hmacHex(Crypto::decrypt($h->secret_encrypted), $body);
                    }
                    $res = Http::withHeaders($headers)->timeout(8)->withBody($body, 'application/json')->post($h->destination_url);
                    if (! $res->successful()) {
                        throw new \RuntimeException("Outbound {$event} returned HTTP {$res->status()}");
                    }
                    DB::table('outbound_webhooks')->where('id', $h->id)->update(['last_sent_at' => now()]);
                } catch (Throwable $e) {
                    Errors::capture($e, ['source' => 'webhook', 'level' => 'warning', 'route' => "outbound:{$event}", 'tenantId' => $tenantId, 'context' => ['host' => parse_url($h->destination_url, PHP_URL_HOST)]]);
                }
            }
        });
    }
}
