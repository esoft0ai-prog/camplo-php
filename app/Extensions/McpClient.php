<?php

namespace App\Extensions;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Calling other platforms' MCP servers on a workspace's behalf (Streamable HTTP, JSON-RPC 2.0).
 * Enforced here, not in a prompt: https only and never private-network hosts; only read-only tools are offered or
 * called; every call times out after 10 seconds and its output is truncated and treated as untrusted data.
 */
final class McpClient
{
    private const PRIVATE = '/^(localhost|127\.|10\.|192\.168\.|169\.254\.|172\.(1[6-9]|2\d|3[01])\.|0\.|\[?::1\]?|\[?f[cd][0-9a-f]{2}:)/i';

    private const READ_NAME = '/^(get|list|search|find|read|fetch|query|describe|lookup|retrieve|show|view|count)[_-]/i';

    public const PROTOCOL = '2025-06-18';

    public static function checkUrl(string $raw): string
    {
        $u = parse_url($raw);
        if (! $u || empty($u['scheme']) || empty($u['host'])) {
            throw new RuntimeException('Enter a valid https URL.');
        }
        $testLocal = app()->environment('testing') && in_array($u['host'], ['127.0.0.1', 'localhost'], true);
        if (! in_array($u['scheme'], ['https', 'http'], true) || (! $testLocal && $u['scheme'] !== 'https')) {
            throw new RuntimeException('MCP servers must use https.');
        }
        if (! $testLocal && preg_match(self::PRIVATE, $u['host'])) {
            throw new RuntimeException('Private network addresses are not allowed.');
        }

        return $raw;
    }

    /** At connect time, also refuse public names that resolve to private or reserved addresses. */
    private static function checkResolved(string $url): void
    {
        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
        if (app()->environment('testing') && in_array($host, ['127.0.0.1', 'localhost'], true)) {
            return;
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('Private network addresses are not allowed.');
            }
        }
    }

    public static function isReadOnly(array $tool): bool
    {
        $ann = $tool['annotations'] ?? [];

        return ($ann['readOnlyHint'] ?? null) === true || (preg_match(self::READ_NAME, $tool['name'] ?? '') && ($ann['destructiveHint'] ?? null) !== true);
    }

    /** A short session: initialize → initialized → $fn(rpc) → DELETE session. */
    private static function session(string $url, ?string $token, callable $fn): mixed
    {
        self::checkUrl($url);
        self::checkResolved($url);
        $deadline = microtime(true) + 10;
        $sessionId = null;
        $id = 0;
        $send = function (string $method, array $params = [], bool $notify = false) use ($url, $token, &$sessionId, &$id, $deadline) {
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                throw new RuntimeException('The MCP server did not answer within 10 seconds.');
            }
            $headers = ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => self::PROTOCOL];
            if ($token) {
                $headers['Authorization'] = "Bearer {$token}";
            }
            if ($sessionId) {
                $headers['Mcp-Session-Id'] = $sessionId;
            }
            $msg = ['jsonrpc' => '2.0', 'method' => $method] + ($params ? ['params' => $params] : []);
            if (! $notify) {
                $msg['id'] = ++$id;
            }
            try {
                $res = Http::withHeaders($headers)->withoutRedirecting()->timeout(max(1, (int) ceil($left)))->withBody(json_encode($msg), 'application/json')->post($url);
            } catch (\Throwable $e) {
                throw new RuntimeException(str_contains($e->getMessage(), 'timed out') ? 'The MCP server did not answer within 10 seconds.' : 'Could not reach the MCP server.');
            }
            if ($res->header('Mcp-Session-Id')) {
                $sessionId = $res->header('Mcp-Session-Id');
            }
            if ($notify) {
                return null;
            }
            if ($res->status() === 401 || $res->status() === 403) {
                throw new RuntimeException('The MCP server rejected the token.');
            }
            if (! $res->successful()) {
                throw new RuntimeException("The MCP server returned HTTP {$res->status()}.");
            }
            $reply = self::pick((string) $res->header('Content-Type'), $res->body(), $id);
            if (isset($reply['error'])) {
                throw new RuntimeException('MCP error: '.mb_substr((string) ($reply['error']['message'] ?? 'unknown'), 0, 200));
            }

            return $reply['result'] ?? [];
        };
        $send('initialize', ['protocolVersion' => self::PROTOCOL, 'capabilities' => (object) [], 'clientInfo' => ['name' => 'camplo', 'version' => '1.0.0']]);
        $send('notifications/initialized', [], true);
        try {
            return $fn($send);
        } finally {
            if ($sessionId) {
                try {
                    Http::withHeaders(['Mcp-Session-Id' => $sessionId] + ($token ? ['Authorization' => "Bearer {$token}"] : []))->withoutRedirecting()->timeout(2)->delete($url);
                } catch (\Throwable) {
                }
            }
        }
    }

    /** The JSON-RPC response with our id, from a JSON body or an SSE stream. */
    private static function pick(string $type, string $body, int $id): array
    {
        $candidates = [];
        if (str_contains($type, 'text/event-stream')) {
            foreach (preg_split('/\r?\n\r?\n/', $body) as $evt) {
                $data = implode("\n", array_map(fn ($l) => ltrim(substr($l, 5)), array_filter(preg_split('/\r?\n/', $evt), fn ($l) => str_starts_with($l, 'data:'))));
                if ($data !== '' && is_array($j = json_decode($data, true))) {
                    $candidates[] = $j;
                }
            }
        } elseif (is_array($j = json_decode($body, true))) {
            $candidates = array_is_list($j) ? $j : [$j];
        }
        foreach ($candidates as $c) {
            if (($c['id'] ?? null) === $id) {
                return $c;
            }
        }
        throw new RuntimeException('The MCP server sent an unreadable reply.');
    }

    /** @return array<int,array{name:string,description:string,inputSchema:array,readOnly:bool}> */
    public static function listTools(string $url, ?string $token): array
    {
        return self::session($url, $token, fn ($rpc) => array_map(fn ($t) => [
            'name' => (string) $t['name'], 'description' => mb_substr((string) ($t['description'] ?? ''), 0, 400), 'inputSchema' => (array) ($t['inputSchema'] ?? []), 'readOnly' => self::isReadOnly($t),
        ], $rpc('tools/list')['tools'] ?? []));
    }

    public static function callTool(string $url, ?string $token, string $name, array $args): string
    {
        return self::session($url, $token, function ($rpc) use ($name, $args) {
            $t = collect($rpc('tools/list')['tools'] ?? [])->firstWhere('name', $name);
            if (! $t || ! self::isReadOnly($t)) {
                throw new RuntimeException("Tool \"{$name}\" is not a read-only tool.");
            }
            $r = $rpc('tools/call', ['name' => $name, 'arguments' => (object) $args]);

            return mb_substr(implode("\n", array_map(fn ($p) => ($p['type'] ?? '') === 'text' ? ($p['text'] ?? '') : '['.($p['type'] ?? '?').']', $r['content'] ?? [])), 0, 6000);
        });
    }
}
