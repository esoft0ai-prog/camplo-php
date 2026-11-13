<?php

namespace Tests\Feature;

use App\Services\Ai;
use App\Services\Engine;
use App\Services\Platform;
use App\Support\Sql;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntelligenceTest extends TestCase
{
    /** @var array<int,array> requests the fake model received */
    private array $calls = [];

    protected function tearDown(): void
    {
        Ai::$fake = null;
        parent::tearDown();
    }

    /** Camplo-paid model (OpenRouter) answered by a closure: fn(array $request) => string. */
    private function model(callable $answer): void
    {
        Platform::saveSection('ai', ['openRouterApiKey' => 'or-test-key'], 'test');
        Ai::$fake = function (array $target, array $r) use ($answer) {
            $this->calls[] = $r;

            return ['text' => $answer($r), 'model' => 'test/model', 'inputTokens' => 100, 'outputTokens' => 20];
        };
    }

    /** A campaign with $overdue unresponded leads (2 h old) and $fresh responded ones. */
    private function campaign(string $name = 'Lagos Launch', int $overdue = 2, int $fresh = 0, string $start = '2026-01-01', ?float $daily = null, ?float $cplThr = null): string
    {
        $tid = $this->tenantId();
        $id = Sql::insertId('campaigns', ['tenant_id' => $tid, 'name' => $name, 'owner_id' => DB::table('users')->where('tenant_id', $tid)->value('id'), 'start_date' => $start,
            'daily_spend' => $daily, 'cpl_threshold' => $cplThr]);
        for ($i = 0; $i < $overdue; $i++) {
            DB::table('leads')->insert(['tenant_id' => $tid, 'campaign_id' => $id, 'full_name' => "Waiting {$i}", 'source_system' => 'test', 'received_at' => now()->subHours(5 + $i)]);
        }
        for ($i = 0; $i < $fresh; $i++) {
            DB::table('leads')->insert(['tenant_id' => $tid, 'campaign_id' => $id, 'full_name' => "Done {$i}", 'source_system' => 'test', 'received_at' => now()->subHours(6),
                'status' => 'responded', 'responded_at' => now()->subHours(6)->addMinutes(5)]);
        }

        return $id;
    }

    public function test_briefing_without_and_with_a_model(): void
    {
        [$tok] = $this->owner();
        $this->campaign();
        $b = $this->as($tok)->getJson('/api/intelligence/briefing')->assertOk();
        $this->assertFalse($b->json('written'));
        $this->assertStringContainsString('2 leads overdue across 1 campaign', $b->json('briefing'));
        $this->assertSame('Priority Flag on Lagos Launch: overdue leads + critical health.', $b->json('urgency.0'));
        $this->assertStringContainsString('Waiting 1', $b->json('urgency.1'));

        $this->model(fn () => json_encode(['briefing' => 'Furthermore, two leads are waiting.', 'anomalies' => [], 'urgency' => ['Call Waiting 1 first.'], 'nudge' => 'Open the SLA screen.']));
        // cached for 30 minutes unless refresh=1
        $this->assertFalse($this->as($tok)->getJson('/api/intelligence/briefing')->json('written'));
        $b = $this->as($tok)->getJson('/api/intelligence/briefing?refresh=1')->assertOk();
        $this->assertTrue($b->json('written'));
        $this->assertSame('Two leads are waiting.', $b->json('briefing'));
        $this->assertSame(['Call Waiting 1 first.'], $b->json('urgency'));
        $this->assertSame('morning_brief', DB::table('ai_workload_logs')->value('task_type'));

        // Starter has no briefing
        [$st] = $this->owner('s@example.com', 'starter');
        $this->as($st)->getJson('/api/intelligence/briefing')->assertStatus(403);
    }

    public function test_chat_rule_based_small_talk_and_model(): void
    {
        [$tok] = $this->owner();
        $cid = $this->campaign();
        $r = $this->as($tok)->postJson('/api/chat/message', ['content' => 'Which lead is most overdue?'])->assertOk();
        $this->assertStringContainsString('**2 leads are overdue**', $r->json('content'));
        $this->assertSame('quick', $r->json('workload'));
        $this->assertContains('2 overdue leads', $r->json('sources'));

        $hi = $this->as($tok)->postJson('/api/chat/message', ['content' => 'hello'])->assertOk();
        $this->assertStringStartsWith('Hey Olu.', $hi->json('content'));
        $this->assertSame([], $hi->json('sources'));

        $this->model(function ($r) {
            if (($r['taskType'] ?? '') === 'worker') {
                return json_encode(['summary' => 'Overdue leads drive the drop.', 'findings' => ['2 overdue'], 'evidence' => [], 'confidence' => 'medium', 'unresolved' => []]);
            }

            return $r['taskType'] === 'chat' ? 'Moreover, **2 leads** need a reply on Lagos Launch.' : '{"facts":[]}';
        });
        $r = $this->as($tok)->postJson('/api/chat/message', ['content' => 'Why is Lagos Launch getting worse this week?', 'depth' => 'Deep'])->assertOk();
        $this->assertSame('**2 leads** need a reply on Lagos Launch.', $r->json('content'));
        $this->assertTrue($r->json('investigated'));
        $this->assertSame(['campaign_analysis', 'investigation'], array_column($r->json('workers'), 'capability'));
        $this->assertSame('deep', $r->json('workload'));
        $this->assertContains('Lagos Launch', $r->json('sources'));
        $logs = DB::table('agent_worker_logs')->get();
        $this->assertCount(2, $logs);
        $this->assertSame($cid, $logs[0]->campaign_id);
        $this->assertSame('completed', $logs[0]->status);
        // The chat context carried the workspace snapshot and the worker findings, never instructions from data.
        $chat = collect($this->calls)->firstWhere('taskType', 'chat');
        $this->assertStringContainsString('"worker_findings"', $chat['system']);
        $this->assertSame(6, DB::table('chat_messages')->count());
        $hist = $this->as($tok)->getJson('/api/chat/history')->assertOk();
        $this->assertContains('deep', array_column($hist->json('data'), 'workload'));

        $this->as($tok)->postJson('/api/chat/message', ['content' => '   '])->assertStatus(400);
    }

    public function test_engine_level1_cards_are_deduplicated_and_priority_flag(): void
    {
        [$tok] = $this->owner();
        $cid = $this->campaign(overdue: 3);
        $t = DB::table('tenants')->first();
        $this->assertSame(2, Engine::level1($t)); // SLA card + Priority Flag (overdue + acknowledgment red)
        $types = DB::table('insights')->pluck('type')->sort()->values()->all();
        $this->assertSame(['alert', 'priority_flag'], $types);
        $this->assertSame(0, Engine::level1($t)); // refreshed in place, not duplicated
        $this->assertSame(2, DB::table('insights')->count());
        // a young campaign with a soft signal gets a monitoring card instead of a recommendation
        $young = $this->campaign('Young', overdue: 1, start: now()->subDays(3)->toDateString());
        Engine::level1($t);
        $this->assertSame('insufficient_evidence', DB::table('insights')->where('dedupe_key', "ie:{$young}")->value('type'));
        $this->assertStringContainsString('3 leads are overdue on Lagos Launch.', DB::table('insights')->where('dedupe_key', "sla:{$cid}")->value('observation'));
        $this->assertSame(1, DB::table('notifications')->where('description', 'Priority Flag raised on Lagos Launch')->count());

        // dismissed cards stay dismissed for a day
        $sla = DB::table('insights')->where('dedupe_key', "sla:{$cid}")->value('id');
        $this->as($tok)->postJson("/api/insights/{$sla}/dismiss")->assertOk();
        Engine::level1($t);
        $this->assertSame(1, DB::table('insights')->where('dedupe_key', "sla:{$cid}")->count());

        // manual refresh runs once, then is debounced
        $r = $this->as($tok)->postJson('/api/insights/refresh')->assertOk();
        $this->assertTrue($r->json('refreshed'));
        $r = $this->as($tok)->postJson('/api/insights/refresh')->assertOk();
        $this->assertFalse($r->json('refreshed'));
        $this->assertSame(15, $r->json('retryInMinutes'));
    }

    public function test_level2_recommendation_and_memory_measurement(): void
    {
        [$tok] = $this->owner();
        $cid = $this->campaign(overdue: 4, fresh: 1, start: now()->subDays(30)->toDateString(), daily: 100, cplThr: 10);
        $this->model(fn ($r) => $r['taskType'] === 'recommendation'
            ? json_encode(['action_text' => 'Clear the overdue leads before adding budget.', 'why' => 'Acknowledgment is 20%.', 'evidence' => [['metric' => 'ack rate', 'change' => '20%', 'period' => '7d']],
                'diagnosis' => null, 'expected_outcome' => 'Higher contact rate', 'risk' => 'Low', 'confidence' => 'medium', 'next_step' => 'Open SLA', 'why_now' => 'Leads are waiting',
                'memory_references' => ['not-a-real-memory'], 'apply_action' => ['type' => 'budget', 'platform' => 'meta']])
            : 'none');
        $out = Engine::refresh($this->tenantId(), true);
        $this->assertSame(1, $out['recommendations']);
        $rec = DB::table('campaign_recommendations')->first();
        $this->assertSame(2, $rec->level);
        $this->assertSame($cid, $rec->campaign_id);
        $this->assertSame('[]', $rec->memory_references); // unknown memory ids are dropped
        $this->assertSame('pending_v2', json_decode($rec->apply_action, true)['status']);
        $this->assertSame('standard', DB::table('ai_workload_logs')->where('task_type', 'recommendation')->value('workload_level'));
        $list = $this->as($tok)->getJson('/api/recommendations')->assertOk();
        $this->assertSame('Clear the overdue leads before adding budget.', $list->json('data.0.action_text'));
        // not re-recommended within 4 hours
        $this->assertSame(0, Engine::refresh($this->tenantId(), true)['recommendations']);

        // 7 days after a change, its outcome is measured and remembered
        $ch = Sql::insertId('campaign_changes', ['tenant_id' => $this->tenantId(), 'campaign_id' => $cid, 'change_type' => 'budget', 'change_description' => 'Raised budget',
            'performance_before' => json_encode(['leads_7d' => 1]), 'changed_at' => now()->subDays(8), 'changed_by' => DB::table('users')->value('id')]);
        Engine::measureMemory();
        $this->assertNotNull(DB::table('campaign_changes')->where('id', $ch)->value('performance_after'));
        $this->assertTrue(DB::table('hindsight_retain_log')->where('content_summary', 'like', 'Outcome 7 days after budget change%')->exists());
    }

    public function test_engine_parse_rec_rejects_bad_output(): void
    {
        $this->assertNull(Engine::parseRec(['action_text' => 'x']));
        $this->assertNull(Engine::parseRec('nope'));
        $ok = ['action_text' => 'Do the thing', 'why' => 'w', 'evidence' => [['metric' => 'm', 'change' => 'c', 'period' => 'p']], 'expected_outcome' => 'e', 'risk' => 'r',
            'confidence' => 'high', 'next_step' => 'n', 'why_now' => 'y'];
        $this->assertSame([], Engine::parseRec($ok)['memory_references']);
        $this->assertNull(Engine::parseRec([...$ok, 'confidence' => 'certain']));
        $this->assertNull(Engine::parseRec([...$ok, 'evidence' => []]));
    }
}
