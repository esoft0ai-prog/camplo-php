<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Workers — bounded intelligence tasks created per question and discarded when done. Every Worker runs under a
 * contract enforced here in code (tenant + campaign boundary, tool allowlist, read-only, max depth, tool-call cap,
 * timeout, budget tier). Each run is logged to agent_worker_logs.
 */
final class Workers
{
    public const CAPABILITY_TOOLS = [
        'investigation' => ['get_campaign_metrics', 'get_lead_data', 'get_webhook_health', 'get_campaign_history', 'get_data_sources'],
        'research' => ['get_campaign_metrics', 'get_funnel_data', 'get_data_sources'],
        'campaign_analysis' => ['get_campaign_metrics', 'get_funnel_data', 'get_campaign_history', 'get_data_sources'],
        'conversation_analysis' => ['get_conversation_signals', 'get_lead_data'],
        'strategic_analysis' => ['get_campaign_metrics', 'get_funnel_data', 'get_campaign_history', 'get_team_patterns', 'get_data_sources'],
        'evaluation' => ['get_campaign_history', 'get_campaign_metrics'],
        'execution' => [],
    ];

    private const TIER = ['low' => ['quick', 'utility'], 'medium' => ['standard', 'workhorse'], 'high' => ['deep', 'deep']];

    /** @param array{tenantId:string,capability:string,objective:string,campaignId?:?string,campaignName?:?string,tools?:array,budget?:string,timeout?:int,maxToolCalls?:int} $p */
    public static function contract(array $p): array
    {
        $allowed = self::CAPABILITY_TOOLS[$p['capability']] ?? [];
        $maxT = (int) config('camplo.worker_default_timeout');
        $maxC = (int) config('camplo.worker_max_tool_calls');

        return [
            'workerId' => 'wrk_'.substr((string) Str::uuid(), 0, 12), 'capability' => $p['capability'], 'objective' => $p['objective'],
            'context' => ['campaignId' => $p['campaignId'] ?? null, 'campaignName' => $p['campaignName'] ?? null, 'windowDays' => 14],
            'tools' => array_values(array_filter($p['tools'] ?? $allowed, fn ($t) => in_array($t, $allowed, true))),
            'permissions' => ['read'], 'budget' => $p['budget'] ?? 'medium',
            'timeout' => min($p['timeout'] ?? $maxT, $maxT), 'maxToolCalls' => min($p['maxToolCalls'] ?? $maxC, $maxC),
            'tenantId' => $p['tenantId'], 'campaignId' => $p['campaignId'] ?? null,
        ];
    }

    public static function enforce(array $c, int $depth): void
    {
        $fail = fn (string $m) => throw new WorkerPolicyError($m);
        if (! $c['tenantId']) {
            $fail('Worker contract has no tenant');
        }
        if ($depth > (int) config('camplo.worker_max_depth')) {
            $fail("Worker depth {$depth} exceeds maximum ".config('camplo.worker_max_depth'));
        }
        if ($c['capability'] === 'execution') {
            $fail('Execution workers are not enabled in v1');
        }
        if (array_diff($c['permissions'], ['read'])) {
            $fail('Only read permissions are allowed in v1');
        }
        foreach ($c['tools'] as $t) {
            if (! isset(AiTools::DESCRIPTIONS[$t])) {
                $fail("Unknown tool {$t}");
            }
            if (! in_array($t, self::CAPABILITY_TOOLS[$c['capability']] ?? [], true)) {
                $fail("Tool {$t} not permitted for {$c['capability']}");
            }
        }
        if (count($c['tools']) > $c['maxToolCalls']) {
            $fail('Contract tools exceed maxToolCalls');
        }
    }

    /** Camplo code gathers data through the permitted tools; the model only reasons over it. Workers cannot spawn Workers. */
    public static function run(string $plan, array $c, int $depth = 1): array
    {
        self::enforce($c, $depth);
        $started = microtime(true);
        [$workload, $logTier] = self::TIER[$c['budget']];
        $data = [];
        $calls = 0;
        $model = 'none';
        $in = $out = 0;
        $cost = 0.0;
        $byok = false;
        $status = 'completed';
        $output = ['summary' => '', 'findings' => [], 'evidence' => [], 'confidence' => 'low', 'unresolved' => []];
        try {
            foreach ($c['tools'] as $t) {
                if (++$calls > $c['maxToolCalls']) {
                    break;
                }
                $data[$t] = AiTools::call($t, $c['tenantId'], $c['campaignId'], ['query' => $c['objective']]);
            }
            $res = Ai::complete($c['tenantId'], $plan, [
                'workload' => $workload, 'taskType' => 'worker', 'json' => true, 'maxTokens' => 1500, 'timeout' => $c['timeout'],
                'system' => 'You are a Camplo '.str_replace('_', ' ', $c['capability'])." worker. Objective: {$c['objective']}. ".
                    'Reason only over the DATA provided. Never invent numbers. Return JSON: {"summary": string, "findings": string[], "evidence": string[], "confidence": "low"|"medium"|"high", "unresolved": string[]}.',
                'messages' => [['role' => 'user', 'content' => "DATA:\n".mb_substr(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 24000)]],
            ]);
            if ($comp = $res['completion']) {
                ['model' => $model, 'inputTokens' => $in, 'outputTokens' => $out, 'costUsd' => $cost, 'byok' => $byok] = $comp;
                $output = self::parseOutput(Ai::parseJson($comp['text'])) ?? [...$output, 'summary' => mb_substr($comp['text'], 0, 800)];
            } else {
                $output = self::deterministic($c, $data);
            }
            if (microtime(true) - $started > $c['timeout']) {
                $status = 'timeout';
            }
        } catch (Throwable $e) {
            $status = 'failed';
            $output = [...self::deterministic($c, $data), 'unresolved' => ["Worker {$status}"]];
        }
        DB::table('agent_worker_logs')->insert([
            'tenant_id' => $c['tenantId'], 'worker_id' => $c['workerId'], 'capability_type' => $c['capability'], 'objective' => $c['objective'], 'campaign_id' => $c['campaignId'],
            'tools_used' => json_encode(array_keys($data)), 'tool_call_count' => $calls, 'workload_level' => $logTier, 'model_used' => $model,
            'input_tokens' => $in, 'output_tokens' => $out, 'cost_usd' => number_format($cost, 6, '.', ''), 'byok' => $byok, 'status' => $status,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000), 'completed_at' => now(),
        ]);

        return ['workerId' => $c['workerId'], 'capability' => $c['capability'], 'output' => $output, 'status' => $status, 'data' => $data];
    }

    private static function parseOutput(mixed $j): ?array
    {
        if (! is_array($j) || ! is_string($j['summary'] ?? null)) {
            return null;
        }
        $list = fn ($v) => is_array($v) ? array_values(array_filter($v, 'is_string')) : [];

        return ['summary' => $j['summary'], 'findings' => $list($j['findings'] ?? []), 'evidence' => $list($j['evidence'] ?? []),
            'confidence' => in_array($j['confidence'] ?? null, ['low', 'medium', 'high'], true) ? $j['confidence'] : 'low', 'unresolved' => $list($j['unresolved'] ?? [])];
    }

    /** Rule-based digest of gathered data when no model is available. */
    public static function deterministic(array $c, array $data): array
    {
        $f = [];
        foreach ($data['get_campaign_metrics'] ?? [] as $k) {
            if ($k['health'] !== 'healthy') {
                $s = $k['health_signals'];
                $f[] = "{$k['name']} is {$k['health']} (lead volume {$s['lead_volume']}, acknowledgment {$s['acknowledgment']}, webhook {$s['webhook']}).";
            }
            if ($k['cpl'] !== null && $k['cpl_threshold'] !== null && $k['cpl'] > $k['cpl_threshold']) {
                $f[] = "{$k['name']} CPL {$k['cpl']} is above its {$k['cpl_threshold']} threshold.";
            }
        }
        $od = array_values(array_filter($data['get_lead_data']['unresponded'] ?? [], fn ($l) => $l['overdue']));
        if ($od) {
            $f[] = count($od)." leads are overdue; longest waiting is {$od[0]['name']} ({$od[0]['waiting']}).";
        }
        foreach ($data['get_webhook_health'] ?? [] as $h) {
            if ($h['webhook'] === 'offline') {
                $f[] = "Webhook for {$h['page']} is offline.";
            }
        }

        return ['summary' => $f[0] ?? "No anomalies found for: {$c['objective']}", 'findings' => $f, 'evidence' => [], 'confidence' => $f ? 'medium' : 'low', 'unresolved' => []];
    }
}
