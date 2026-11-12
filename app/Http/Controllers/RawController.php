<?php

namespace App\Http\Controllers;

use App\Services\LeadService;
use App\Services\Sweeps;
use App\Support\Crypto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Endpoints outside the JSON API's conventions: signed lead webhooks, live updates, cron and links in emails. */
class RawController
{
    private static function parse(Request $r): mixed
    {
        $text = $r->getContent();
        if (str_contains((string) $r->header('Content-Type'), 'application/x-www-form-urlencoded')) {
            parse_str($text, $o);

            return $o;
        }
        $j = json_decode($text ?: '{}', true);

        return is_array($j) ? $j : [];
    }

    private static function ingestResponse(array $r)
    {
        return response()->json($r['status'] === 201 ? ['ok' => true, 'lead_id' => $r['leadId']] : ['ok' => false], $r['status']);
    }

    public function ingest(Request $r, string $tenantId, string $deploymentId)
    {
        $sig = $r->header('X-Camplo-Signature') ?? $r->header('X-Signature') ?? $r->header('Tally-Signature');

        return self::ingestResponse(LeadService::ingestDeployment($tenantId, $deploymentId, $r->getContent(), $sig, self::parse($r)));
    }

    public function hook(Request $r, string $hookId)
    {
        $auth = [
            'camplo' => $r->header('X-Camplo-Signature') ?? $r->header('X-Signature'), 'tally' => $r->header('Tally-Signature'), 'typeform' => $r->header('Typeform-Signature'),
            'token' => is_string($r->query('token')) ? $r->query('token') : $r->header('X-Camplo-Token'),
        ];

        return self::ingestResponse(LeadService::ingestNamed($hookId, $r->getContent(), $auth, self::parse($r)));
    }

    public function lifecycle(Request $r, string $integrationId, string $token)
    {
        $res = LeadService::ingestLifecycle($integrationId, $token, (array) self::parse($r));

        return response()->json(['ok' => $res['status'] < 300], $res['status']);
    }

    /** A GET must not mutate (mail scanners prefetch links): open the acknowledge screen, which POSTs once. */
    public function acknowledgeLink(string $token)
    {
        if (! preg_match('/^[A-Za-z0-9_-]{10,200}$/', $token)) {
            return response()->json(['code' => 'NOT_FOUND', 'message' => 'This link is no longer valid.'], 404);
        }

        return redirect('/#/acknowledge?token='.rawurlencode($token), 302);
    }

    public function cron(Request $r)
    {
        $secret = (string) config('camplo.cron_secret');
        if ($secret === '' || ! Crypto::safeEqual((string) $r->header('Authorization'), "Bearer {$secret}")) {
            return response()->json(['ok' => false], 401);
        }
        Sweeps::run();

        return ['ok' => true];
    }

    /**
     * Live updates over EventSource. Each request returns the events since the browser's Last-Event-ID and closes;
     * the `retry` hint makes the browser reconnect a few seconds later. No PHP worker is held open.
     */
    public function rt(Request $r, string $name)
    {
        $ctx = ctx();
        $ctx->resolve();
        $channel = "workspace:{$name}";
        $last = $r->header('Last-Event-ID') ?? $r->query('lastEventId');
        $out = "retry: 4000\n\n";
        if ($last === null || ! ctype_digit((string) $last)) {
            // First connection: start from now.
            $max = (int) DB::table('rt_events')->max('id');
            $out .= "id: {$max}\n: connected\n\n";
        } else {
            $rows = DB::table('rt_events')->where('tenant_id', $ctx->tenantId)->where('channel', $channel)->where('id', '>', (int) $last)->orderBy('id')->limit(50)->get();
            $max = max((int) $last, (int) DB::table('rt_events')->max('id'));
            if ($rows->isNotEmpty()) {
                // One update is enough: the client reloads its data on any update.
                $e = $rows->last();
                $out .= "id: {$max}\nevent: update\ndata: ".json_encode((object) (json_decode($e->payload ?? '{}', true) ?: []), JSON_UNESCAPED_SLASHES)."\n\n";
            } else {
                $out .= "id: {$max}\n: ping\n\n";
            }
        }

        return response($out, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache, no-transform', 'X-Accel-Buffering' => 'no']);
    }
}
