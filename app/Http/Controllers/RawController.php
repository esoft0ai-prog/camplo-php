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
        \App\Support\IngestStats::record($r['status'] === 201);
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

    public function polar(Request $r)
    {
        $raw = $r->getContent();
        if (! \App\Services\Billing::verifyPolarSignature($raw, $r->header('webhook-id'), $r->header('webhook-timestamp'), $r->header('webhook-signature'))) {
            return response()->json(['ok' => false], 401);
        }

        return \App\Services\Billing::handlePolarEvent(json_decode($raw, true) ?: []);
    }

    /** Telegram webhooks: the Camplo-wide bot, then per-workspace bots; both carry Telegram's secret_token header. */
    public function telegram(Request $r, ?string $tenantId = null)
    {
        $scope = $tenantId ?? 'global';
        if (! Crypto::safeEqual((string) $r->header('X-Telegram-Bot-Api-Secret-Token'), \App\Services\Telegram::webhookSecretFor($scope))) {
            return response()->json(['ok' => false], 401);
        }
        \App\Services\Telegram::handleUpdate($scope, $r->json()->all());

        return ['ok' => true];
    }

    /** Browser error reports from the app and the Super Admin console (public, rate-limited, size-capped). */
    public function clientError(Request $r)
    {
        if (strlen($r->getContent()) > 32768) {
            return response()->json(['ok' => false], 413);
        }
        $key = 'client-err:'.sha1((string) $r->ip());
        \Illuminate\Support\Facades\Cache::add($key, 0, 60);
        if (\Illuminate\Support\Facades\Cache::increment($key) > 30) {
            return response()->json(['ok' => false], 429);
        }
        $b = $r->json()->all();
        $message = is_string($b['message'] ?? null) ? mb_substr($b['message'], 0, 2000) : '';
        if ($message === '') {
            return response()->json(['ok' => false], 400);
        }
        $who = null;
        try {
            ctx()->resolve();
            $who = ctx()->user;
        } catch (\Throwable) {
        }
        $route = null;
        if (is_string($b['url'] ?? null) && ($u = parse_url($b['url']))) {
            $route = isset($u['fragment']) ? ($u['path'] ?? '/').'#'.explode('?', $u['fragment'])[0] : ($u['path'] ?? '/');
        }
        \App\Support\Errors::capture($message, [
            'source' => ($b['source'] ?? '') === 'admin' ? 'admin_client' : 'client', 'route' => $route, 'type' => 'BrowserError',
            'stack' => is_string($b['stack'] ?? null) ? mb_substr($b['stack'], 0, 8000) : null,
            'userId' => $who->id ?? null, 'tenantId' => $who->tenant_id ?? null,
            'context' => ['userAgent' => mb_substr(is_string($b['userAgent'] ?? null) ? $b['userAgent'] : (string) $r->userAgent(), 0, 300)],
        ]);

        return response()->noContent();
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
