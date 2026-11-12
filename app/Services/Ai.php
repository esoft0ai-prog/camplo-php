<?php

namespace App\Services;

use Anthropic\Client as AnthropicClient;
use Anthropic\Core\Exceptions\APIException;
use App\Support\Crypto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Inference routing.
 *  1. The caller names a workload level (quick / standard / deep / strategic).
 *  2. BYOK: the workspace's primary key when connected (Camplo pays nothing); on failure the fallback key.
 *  3. Otherwise Camplo's OpenRouter account.
 *  4. Every call is logged to ai_workload_logs (never shown to operators).
 * Model names never leave this class; operators only see capability levels. When no provider is available,
 * complete() returns null and callers use their deterministic output.
 */
final class Ai
{
    /** Default Anthropic model per tier when a BYOK config leaves the model blank (same as the Node app). */
    private const ANTHROPIC_TIER_MODEL = ['quick' => 'claude-haiku-4-5', 'standard' => 'claude-sonnet-5', 'deep' => 'claude-sonnet-5', 'strategic' => 'claude-opus-5'];

    private const EFFORT = ['quick' => 'low', 'standard' => 'medium', 'deep' => 'high', 'strategic' => 'high'];

    /** Approximate $/1M tokens [in, out], for internal cost tracking only. */
    private const PRICE = [
        ['/opus-5-5/', 4, 20], ['/opus/', 5, 25], ['/sonnet/', 2, 10], ['/haiku/', 1, 5], ['/gpt-4\.1|gpt-4o(?!-mini)/', 2.5, 10], ['/mini|flash|llama|deepseek|qwen/', 0.3, 1.2],
    ];

    /** OpenAI-compatible endpoints for the non-Anthropic providers a workspace can bring its own key for. */
    private const OPENAI_COMPAT = [
        'openai' => 'https://api.openai.com/v1', 'groq' => 'https://api.groq.com/openai/v1', 'deepseek' => 'https://api.deepseek.com/v1',
        'mistral' => 'https://api.mistral.ai/v1', 'xai' => 'https://api.x.ai/v1', 'gemini' => 'https://generativelanguage.googleapis.com/v1beta/openai',
        'qwen' => 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1', 'kimi' => 'https://api.moonshot.ai/v1', 'glm' => 'https://open.bigmodel.cn/api/paas/v4',
        'minimax' => 'https://api.minimax.io/v1', 'openrouter' => 'https://openrouter.ai/api/v1',
    ];

    /** @var (callable(array, array): array)|null test hook replacing the provider call */
    public static $fake = null;

    private static function cost(string $model, int $in, int $out): float
    {
        foreach (self::PRICE as [$re, $pi, $po]) {
            if (preg_match($re, $model)) {
                return ($in * $pi + $out * $po) / 1_000_000;
            }
        }

        return ($in * 2 + $out * 10) / 1_000_000;
    }

    /** Quick/standard panel work gives up after AI_PANEL_TIMEOUT_SECONDS; deep/strategic after 30s. */
    private static function timeout(array $r): float
    {
        if (! empty($r['timeout'])) {
            return (float) $r['timeout'];
        }

        return in_array($r['workload'], ['quick', 'standard'], true) ? (float) config('camplo.ai_panel_timeout_seconds') : 30.0;
    }

    /** @return array{text:string,model:string,inputTokens:int,outputTokens:int} */
    private static function callAnthropic(array $t, array $r): array
    {
        $client = new AnthropicClient(apiKey: $t['apiKey'], requestOptions: ['timeout' => self::timeout($r), 'maxRetries' => 0]);
        $args = ['model' => $t['model'], 'maxTokens' => $r['maxTokens'] ?? 16000, 'system' => $r['system'], 'messages' => $r['messages']];
        // Effort is not accepted by Haiku 4.5.
        if (! str_contains($t['model'], 'haiku')) {
            $args['outputConfig'] = ['effort' => self::EFFORT[$r['workload']]];
        }
        if ($t['model'] === 'claude-opus-5') {
            // Server-side refusal fallback: a declined request is re-run on a fallback model in the same call.
            $res = $client->beta->messages->create(...$args, fallbacks: 'default', betas: ['server-side-fallback-2026-07-01']);
        } else {
            $res = $client->messages->create(...$args);
        }
        if ($res->stopReason === 'refusal') {
            throw new RuntimeException('model_refusal');
        }
        $text = '';
        foreach ($res->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        return ['text' => trim($text), 'model' => $res->model, 'inputTokens' => (int) $res->usage->inputTokens, 'outputTokens' => (int) $res->usage->outputTokens];
    }

    private static function callOpenAiCompatible(array $t, array $r, string $base): array
    {
        $res = Http::withToken($t['apiKey'])->withHeaders(['X-Title' => 'Camplo'])->timeout((int) ceil(self::timeout($r)))->post("{$base}/chat/completions", [
            'model' => $t['model'], 'max_tokens' => $r['maxTokens'] ?? 4000,
            'messages' => [['role' => 'system', 'content' => $r['system']], ...$r['messages']],
            ...(! empty($r['json']) ? ['response_format' => ['type' => 'json_object']] : []),
        ]);
        if (! $res->successful()) {
            throw new RuntimeException("provider_http_{$res->status()}");
        }

        return ['text' => trim((string) $res->json('choices.0.message.content', '')), 'model' => (string) ($res->json('model') ?? $t['model']),
            'inputTokens' => (int) $res->json('usage.prompt_tokens', 0), 'outputTokens' => (int) $res->json('usage.completion_tokens', 0)];
    }

    private static function callTarget(array $t, array $r): array
    {
        if (self::$fake) {
            return (self::$fake)($t, $r);
        }
        if ($t['provider'] === 'anthropic') {
            return self::callAnthropic($t, $r);
        }
        $base = self::OPENAI_COMPAT[$t['provider']] ?? null;
        if (! $base) {
            throw new RuntimeException("unsupported_provider_{$t['provider']}");
        }

        return self::callOpenAiCompatible($t, $r, $base);
    }

    private static function modelFor(string $provider, ?string $configured, string $w, array $models): string
    {
        if ($configured) {
            return $configured;
        }

        return $provider === 'anthropic' ? self::ANTHROPIC_TIER_MODEL[$w] : $models[$w];
    }

    /** Ordered targets for a workspace: primary BYOK → fallback BYOK → Camplo. */
    public static function targetsFor(string $tenantId, string $w): array
    {
        $cfg = DB::table('ai_provider_configs')->where('tenant_id', $tenantId)->first();
        $ai = Platform::get()['ai'];
        $out = [];
        try {
            if ($cfg && $cfg->primary_api_key_encrypted && $cfg->primary_provider && $cfg->primary_status !== 'failed') {
                $out[] = ['provider' => $cfg->primary_provider, 'model' => self::modelFor($cfg->primary_provider, $cfg->primary_model_name, $w, $ai['models']), 'apiKey' => Crypto::decrypt($cfg->primary_api_key_encrypted), 'byok' => true];
            }
            if ($cfg && $cfg->fallback_enabled && $cfg->fallback_api_key_encrypted && $cfg->fallback_provider) {
                $out[] = ['provider' => $cfg->fallback_provider, 'model' => self::modelFor($cfg->fallback_provider, $cfg->fallback_model_name, $w, $ai['models']), 'apiKey' => Crypto::decrypt($cfg->fallback_api_key_encrypted), 'byok' => true];
            }
        } catch (Throwable) {
            // an unreadable stored key is treated as absent
        }
        if ($ai['openRouterApiKey']) {
            $out[] = ['provider' => 'openrouter', 'model' => $ai['models'][$w], 'apiKey' => $ai['openRouterApiKey'], 'byok' => false];
        }

        return $out;
    }

    /**
     * One completion. Request keys: workload, taskType, system, messages, maxTokens?, json?, timeout? (seconds).
     *
     * @return array{completion:?array,usingFallback:bool,downgraded:bool,error?:string}
     */
    public static function complete(string $tenantId, string $plan, array $r): array
    {
        $workload = $r['workload'];
        $downgraded = false;
        $targets = self::targetsFor($tenantId, $workload);
        // Camplo-paid Deep/Strategic work stops at 100% of the plan's advanced capacity; Standard stays available.
        if (in_array($workload, ['deep', 'strategic'], true) && ! array_filter($targets, fn ($t) => $t['byok']) && Metrics::advancedUsage($tenantId, $plan) >= 1) {
            $workload = 'standard';
            $downgraded = true;
            $targets = self::targetsFor($tenantId, $workload);
        }
        $last = null;
        foreach ($targets as $i => $t) {
            try {
                $res = self::callTarget($t, [...$r, 'workload' => $workload]);
                $cost = $t['byok'] ? 0.0 : self::cost($res['model'], $res['inputTokens'], $res['outputTokens']);
                DB::table('ai_workload_logs')->insert(['tenant_id' => $tenantId, 'task_type' => $r['taskType'], 'workload_level' => $workload, 'model_used' => $res['model'],
                    'input_tokens' => $res['inputTokens'], 'output_tokens' => $res['outputTokens'], 'cost_usd' => number_format($cost, 6, '.', ''), 'byok' => $t['byok']]);
                $usingFallback = $t['byok'] && $i > 0 && $targets[0]['byok'];
                DB::table('ai_provider_configs')->where('tenant_id', $tenantId)->update(['using_fallback' => $usingFallback]);

                return ['completion' => [...$res, 'byok' => $t['byok'], 'costUsd' => $cost], 'usingFallback' => $usingFallback, 'downgraded' => $downgraded];
            } catch (APIException|Throwable $e) {
                $last = $e->getMessage();
                if ($t['byok'] && $i === 0) {
                    DB::table('ai_provider_configs')->where('tenant_id', $tenantId)->update(['primary_status' => 'failed']);
                }
            }
        }

        return ['completion' => null, 'usingFallback' => false, 'downgraded' => $downgraded, 'error' => $last ?? 'no_provider'];
    }

    /** One completion's text, or null when no model is available. */
    public static function text(string $tenantId, string $plan, array $r): ?string
    {
        $t = self::complete($tenantId, $plan, $r)['completion']['text'] ?? null;

        return $t === '' ? null : $t;
    }

    /** Verify a workspace's own key with a minimal call (Settings → AI Provider → Verify). */
    public static function verifyProvider(string $provider, ?string $model, string $apiKey): bool
    {
        try {
            $res = self::callTarget(['provider' => $provider, 'model' => self::modelFor($provider, $model, 'quick', Platform::get()['ai']['models']), 'apiKey' => $apiKey, 'byok' => true],
                ['workload' => 'quick', 'taskType' => 'chat', 'system' => 'Reply with OK.', 'messages' => [['role' => 'user', 'content' => 'ping']], 'maxTokens' => 16]);

            return is_string($res['text']);
        } catch (Throwable) {
            return false;
        }
    }

    /** The first JSON object/array in a model response. */
    public static function parseJson(string $text): mixed
    {
        if (! preg_match('/[\[{][\s\S]*[\]}]/', $text, $m)) {
            return null;
        }
        $j = json_decode($m[0], true);

        return json_last_error() === JSON_ERROR_NONE ? $j : null;
    }
}
