<?php

namespace App\Extensions;

use App\Services\Ai;
use App\Support\Crypto;
use App\Support\Fmt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * How extensions reach the assistant:
 *   - skills: enabled skills whose triggers match the question add their instructions to the system prompt
 *   - MCP servers: the workspace's own model picks at most two read-only tool calls; results enter the context as
 *     untrusted data
 */
final class Assistant
{
    private static function enabled(string $tenantId, string $kind): array
    {
        $plan = DB::table('tenants')->where('id', $tenantId)->value('plan');
        if (! $plan) {
            return [];
        }
        $rows = DB::table('workspace_extensions')->where('tenant_id', $tenantId)->where('kind', $kind)->where('enabled', true)->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $cat = collect(Catalog::all())->keyBy('key');
        $out = [];
        foreach ($rows as $r) {
            if (($item = $cat[$r->key] ?? null) && Catalog::planAllows($plan, $item)) {
                $out[] = ['row' => $r, 'item' => $item];
            }
        }

        return $out;
    }

    private static function bump(string $id): void
    {
        DB::table('workspace_extensions')->where('id', $id)->update(['usage_count' => DB::raw('usage_count + 1'), 'last_used_at' => now()]);
    }

    /** Skills whose triggers appear in the question (at most two, most specific first). */
    public static function skillsFor(string $tenantId, string $question): array
    {
        $q = mb_strtolower($question);
        $scored = [];
        foreach (self::enabled($tenantId, 'skill') as $x) {
            $score = 0;
            foreach (array_filter((array) ($x['item']['config']['triggers'] ?? []), 'is_string') as $t) {
                if ($t !== '' && str_contains($q, mb_strtolower($t))) {
                    $score += mb_strlen($t);
                }
            }
            if ($score > 0) {
                $scored[] = [...$x, 'score' => $score];
            }
        }
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        $scored = array_slice($scored, 0, 2);
        foreach ($scored as $s) {
            self::bump($s['row']->id);
        }

        return array_map(fn ($s) => ['key' => $s['item']['key'], 'name' => $s['item']['name'], 'instructions' => mb_substr((string) ($s['item']['config']['instructions'] ?? ''), 0, 2000)], $scored);
    }

    /** Let the workspace's model choose up to two read-only MCP tool calls, run them, return the results. */
    public static function mcpToolResults(string $tenantId, string $plan, string $question): array
    {
        $menu = [];
        foreach (self::enabled($tenantId, 'mcp_server') as ['row' => $row, 'item' => $item]) {
            if ($row->status !== 'ok') {
                continue;
            }
            $settings = (array) (Fmt::json($row->settings) ?? []);
            $tools = array_slice(array_values(array_filter((array) ($settings['tools'] ?? []), fn ($t) => ! empty($t['readOnly']))), 0, 25);
            $url = (string) (($settings['url'] ?? '') ?: ($item['config']['url'] ?? ''));
            if (! $tools || ! $url) {
                continue;
            }
            $token = null;
            try {
                $token = $row->secrets_encrypted ? (json_decode(Crypto::decrypt($row->secrets_encrypted), true)['token'] ?? null) : null;
            } catch (Throwable) {
            }
            $menu[] = ['server' => $item['name'], 'key' => $item['key'], 'url' => $url, 'token' => $token, 'tools' => $tools, 'id' => $row->id];
        }
        if (! $menu) {
            return [];
        }
        $res = Ai::complete($tenantId, $plan, ['workload' => 'quick', 'taskType' => 'worker', 'json' => true, 'maxTokens' => 400,
            'system' => 'You decide whether outside tools would help answer a campaign-operations question. Only pick a tool when the question clearly needs data from that platform. Return JSON only: {"calls":[{"server":"<server key>","tool":"<tool name>","args":{...}}]} with at most 2 calls, or {"calls":[]}.',
            'messages' => [['role' => 'user', 'content' => 'QUESTION: '.mb_substr($question, 0, 1500)."\n\nTOOLS:\n".mb_substr(json_encode(array_map(fn ($m) => ['server' => $m['key'], 'name' => $m['server'],
                'tools' => array_map(fn ($t) => ['name' => $t['name'], 'description' => $t['description'], 'input' => (object) ($t['inputSchema'] ?? [])], $m['tools'])], $menu), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 12000)]]]);
        $calls = array_slice((array) ((Ai::parseJson((string) ($res['completion']['text'] ?? '')) ?? [])['calls'] ?? []), 0, 2);
        $out = [];
        foreach ($calls as $c) {
            $m = collect($menu)->firstWhere('key', $c['server'] ?? null);
            $tool = $c['tool'] ?? null;
            if (! $m || ! is_string($tool) || ! collect($m['tools'])->contains('name', $tool)) {
                continue;
            }
            try {
                $out[] = ['server' => $m['server'], 'tool' => $tool, 'result' => McpClient::callTool($m['url'], $m['token'], $tool, (array) ($c['args'] ?? []))];
                self::bump($m['id']);
            } catch (Throwable $e) {
                $out[] = ['server' => $m['server'], 'tool' => $tool, 'result' => 'Tool failed: '.$e->getMessage()];
            }
        }

        return $out;
    }
}
