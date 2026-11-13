<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Error monitoring. Unexpected server errors, failed jobs, failed outbound webhooks and browser errors are grouped by
 * fingerprint into `error_events` (Super Admin → Errors). With SENTRY_DSN set, each is also sent to Sentry (or any
 * Sentry-compatible service). Capturing never throws. Request bodies, cookies and headers are not recorded.
 */
final class Errors
{
    private const UUIDISH = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i';

    /** Same error, different ids/numbers → same group. */
    public static function fingerprint(string $source, string $message, ?string $route): string
    {
        $norm = fn (string $s) => substr(preg_replace('/\b\d+\b/', 'N', preg_replace(self::UUIDISH, ':id', $s)), 0, 300);

        return substr(hash('sha256', "{$source}|{$norm($message)}|{$norm($route ?? '')}"), 0, 64);
    }

    /** @param array{source?:string,level?:string,route?:?string,tenantId?:?string,userId?:?string,context?:array} $c */
    public static function capture(Throwable|string $err, array $c = []): void
    {
        try {
            $source = $c['source'] ?? 'server';
            $level = $c['level'] ?? 'error';
            $message = $err instanceof Throwable ? ($err->getMessage() ?: class_basename($err)) : $err;
            $stack = $err instanceof Throwable ? $err->getTraceAsString() : ($c['stack'] ?? null);
            $type = $err instanceof Throwable ? class_basename($err) : ($c['type'] ?? 'Error');
            $route = isset($c['route']) && $c['route'] !== null ? substr(explode('?', $c['route'])[0], 0, 500) : null;
            if (! app()->environment('testing')) {
                Log::error("[{$source}]".($route ? " {$route}" : '').' '.$message);
            }
            $ctx = isset($c['context']) ? json_encode($c['context']) : null;
            $tenant = isset($c['tenantId']) && Str::isUuid((string) $c['tenantId']) ? $c['tenantId'] : null;
            $user = isset($c['userId']) && Str::isUuid((string) $c['userId']) ? $c['userId'] : null;
            DB::statement(Sql::mysql()
                ? 'insert into error_events (fingerprint, source, level, message, stack, route, tenant_id, user_id, context) values (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    on duplicate key update count = count + 1, last_seen_at = now(3), resolved_at = null, stack = values(stack),
                    tenant_id = values(tenant_id), user_id = values(user_id), context = values(context)'
                : 'insert into error_events (fingerprint, source, level, message, stack, route, tenant_id, user_id, context) values (?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb)
                    on conflict (fingerprint) do update set count = error_events.count + 1, last_seen_at = now(), resolved_at = null, stack = excluded.stack,
                    tenant_id = excluded.tenant_id, user_id = excluded.user_id, context = excluded.context',
                [self::fingerprint($source, $message, $route), $source, $level, mb_substr($message, 0, 4000), $stack ? mb_substr($stack, 0, 8000) : null, $route, $tenant, $user, $ctx]);
            self::toSentry($message, $stack, $type, $level, $route, $source, $tenant, $user, $c['context'] ?? []);
        } catch (Throwable $e) {
            Log::error('[monitor] capture failed: '.$e->getMessage());
        }
    }

    /** https://<key>@o123.ingest.sentry.io/456 → envelope endpoint + auth header. */
    public static function sentryTarget(string $dsn): ?array
    {
        $u = parse_url($dsn);
        $project = trim($u['path'] ?? '', '/');
        if (empty($u['user']) || $project === '' || empty($u['host'])) {
            return null;
        }
        $host = $u['host'].(isset($u['port']) ? ':'.$u['port'] : '');

        return ['url' => "{$u['scheme']}://{$host}/api/{$project}/envelope/", 'auth' => "Sentry sentry_version=7, sentry_key={$u['user']}, sentry_client=camplo/1.0"];
    }

    private static function toSentry(string $message, ?string $stack, string $type, string $level, ?string $route, string $source, ?string $tenant, ?string $user, array $context): void
    {
        $dsn = (string) config('camplo.sentry_dsn');
        $t = $dsn ? self::sentryTarget($dsn) : null;
        if (! $t) {
            return;
        }
        $id = str_replace('-', '', (string) Str::uuid());
        $event = [
            'event_id' => $id, 'timestamp' => microtime(true), 'platform' => str_ends_with($source, 'client') ? 'javascript' : 'php', 'level' => $level,
            'environment' => app()->environment(), 'server_name' => 'camplo', 'exception' => ['values' => [['type' => $type, 'value' => $message]]],
            'tags' => array_filter(['source' => $source, 'route' => $route, 'tenant' => $tenant]), 'user' => $user ? ['id' => $user] : null, 'extra' => ['stack' => $stack, ...$context],
        ];
        $body = json_encode(['event_id' => $id, 'dsn' => $dsn])."\n".json_encode(['type' => 'event'])."\n".json_encode($event);
        try {
            Http::withHeaders(['Content-Type' => 'application/x-sentry-envelope', 'X-Sentry-Auth' => $t['auth']])->timeout(5)->withBody($body, 'application/x-sentry-envelope')->post($t['url']);
        } catch (Throwable) {
        }
    }
}
