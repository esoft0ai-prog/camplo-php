<?php

namespace Tests\Feature;

use App\Services\Ai;
use App\Services\LeadService;
use App\Services\Platform;
use App\Services\Plugins;
use App\Support\Sql;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExtensionsTest extends TestCase
{
    protected function tearDown(): void
    {
        Ai::$fake = null;
        parent::tearDown();
    }

    private function mcp(string $token, array $msg)
    {
        $this->flushHeaders();

        return $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/mcp', $msg);
    }

    public function test_catalog_plan_locks_and_skills(): void
    {
        [$tok] = $this->owner();
        $r = $this->as($tok)->getJson('/api/extensions')->assertOk();
        $items = collect($r->json('items'))->keyBy('key');
        $this->assertFalse($items['skill_cpl_diagnostician']['locked']);
        $this->assertTrue($items['mcp_linear']['locked']); // Watchtower
        $this->assertSame('/api/mcp', parse_url($r->json('camploMcp.endpoint'), PHP_URL_PATH));

        $this->as($tok)->patchJson('/api/extensions/mcp_linear', ['token' => 'x'])->assertStatus(403)->assertJsonPath('reason', 'plan_required');
        $this->as($tok)->patchJson('/api/extensions/nope_nope', [])->assertStatus(404);
        $v = $this->as($tok)->patchJson('/api/extensions/skill_cpl_diagnostician', ['enabled' => true])->assertOk();
        $this->assertTrue($v->json('active'));
        $this->assertSame(1, $this->as($tok)->getJson('/api/extensions')->json('summary.active'));

        // Members can't manage extensions
        $mem = $this->teammate($tok, 'm@example.com');
        $this->flushHeaders();
        $this->as($mem)->getJson('/api/extensions')->assertStatus(403);

        // The skill reaches the chat prompt when its triggers match
        Platform::saveSection('ai', ['openRouterApiKey' => 'k'], 'test');
        $prompts = [];
        Ai::$fake = function ($t, $r) use (&$prompts) {
            $prompts[] = $r['system'];

            return ['text' => $r['taskType'] === 'chat' ? 'ok' : '{"facts":[]}', 'model' => 'm', 'inputTokens' => 1, 'outputTokens' => 1];
        };
        $this->flushHeaders();
        $this->as($tok)->postJson('/api/chat/message', ['content' => 'Our cost per lead is too expensive, what now?'])->assertOk()->assertJsonFragment(['skill: CPL diagnostician']);
        $this->assertStringContainsString('ACTIVE SKILLS', implode("\n", $prompts));
        $this->assertSame(1, (int) DB::table('workspace_extensions')->where('key', 'skill_cpl_diagnostician')->value('usage_count'));

        $this->as($tok)->deleteJson('/api/extensions/skill_cpl_diagnostician')->assertOk();
        $this->as($tok)->deleteJson('/api/extensions/skill_cpl_diagnostician')->assertStatus(404);
    }

    public function test_camplo_mcp_server_tokens_and_tools(): void
    {
        [$tok] = $this->owner();
        $tid = $this->tenantId();
        $cid = Sql::insertId('campaigns', ['tenant_id' => $tid, 'name' => 'Abuja Promo', 'owner_id' => DB::table('users')->value('id'), 'start_date' => '2026-01-01']);
        DB::table('leads')->insert(['tenant_id' => $tid, 'campaign_id' => $cid, 'full_name' => 'Ada Late', 'email' => 'ada@example.com', 'source_system' => 'test', 'received_at' => now()->subHours(9)]);

        $made = $this->as($tok)->postJson('/api/extensions/mcp-tokens', ['name' => 'Claude'])->assertOk();
        $token = $made->json('token');
        $this->assertStringStartsWith('cmcp_', $token);
        $this->assertSame(substr($token, 0, 12), $made->json('prefix'));
        $this->assertSame(64, strlen(DB::table('mcp_tokens')->value('token_hash'))); // only the hash is stored

        // Switched off until the workspace activates it
        $this->mcp($token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertStatus(403)->assertJsonPath('error.message', 'The Camplo MCP server is switched off for this workspace.');
        $this->flushHeaders();
        $this->as($tok)->patchJson('/api/extensions/camplo_mcp', ['enabled' => true])->assertOk();

        $init = $this->mcp($token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '1']]])->assertOk();
        $this->assertSame('2025-03-26', $init->json('result.protocolVersion'));
        $this->assertSame('camplo', $init->json('result.serverInfo.name'));
        $this->mcp($token, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])->assertStatus(202);
        $tools = $this->mcp($token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])->assertOk()->json('result.tools');
        $this->assertContains('team_patterns', array_column($tools, 'name')); // owner token
        $this->assertTrue(collect($tools)->every(fn ($t) => $t['annotations']['readOnlyHint'] === true));

        $call = fn (string $name, array $args = []) => json_decode($this->mcp($token, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $args]])
            ->assertOk()->json('result.content.0.text'), true);
        $this->assertSame('Abuja Promo', $call('list_campaigns')[0]['name']);
        $od = $call('list_overdue_leads', ['campaign' => 'abuja']);
        $this->assertTrue($od['unresponded'][0]['overdue']);
        $this->assertSame('Ada Late', $call('search_leads', ['query' => 'ada'])[0]['name']);
        $this->assertSame(1, $call('sla_status')['overdue']);
        $this->assertSame(1, $call('morning_brief')['overdue']);
        $this->assertSame('No campaign matches "zzz".', $call('get_campaign', ['campaign' => 'zzz'])['error']);
        $this->mcp($token, ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'delete_everything']])->assertOk()->assertJsonPath('error.code', -32602);
        $this->assertGreaterThanOrEqual(6, (int) DB::table('workspace_extensions')->where('key', 'camplo_mcp')->value('usage_count'));
        $this->assertNotNull(DB::table('mcp_tokens')->value('last_used_at'));
        $this->flushHeaders();
        $this->get('/api/mcp')->assertStatus(405);

        // A member's token sees no team patterns; a revoked token is refused.
        $mem = $this->teammate($tok, 'm@example.com', 'admin');
        $this->flushHeaders();
        DB::table('users')->where('email', 'm@example.com')->update(['permissions' => json_encode(['extensions.manage' => true])]);
        $mt = $this->as($mem)->postJson('/api/extensions/mcp-tokens', ['name' => 'Cursor'])->json('token');
        $this->assertNotNull($mt);

        $this->assertNotContains('team_patterns', array_column($this->mcp($mt, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->json('result.tools'), 'name'));

        $this->flushHeaders();
        $this->as($tok)->deleteJson('/api/extensions/mcp-tokens/'.$made->json('id'))->assertOk();
        $this->mcp($token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertStatus(401);
        $this->mcp('nope', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertStatus(401);
    }

    public function test_remote_mcp_server_connect_and_assistant_tool_use(): void
    {
        [$tok] = $this->owner('w@example.com', 'watchtower');
        $seen = [];
        Http::fake(['127.0.0.1/*' => function (HttpRequest $req) use (&$seen) {
            if ($req->method() === 'DELETE') {
                return Http::response('', 200);
            }
            $m = json_decode($req->body(), true);
            $seen[] = [$m['method'], $req->header('Authorization')[0] ?? null, $req->header('Mcp-Session-Id')[0] ?? null];
            if (! isset($m['id'])) {
                return Http::response('', 202);
            }
            $result = match ($m['method']) {
                'initialize' => ['protocolVersion' => '2025-06-18', 'capabilities' => ['tools' => []], 'serverInfo' => ['name' => 'fake', 'version' => '1']],
                'tools/list' => ['tools' => [
                    ['name' => 'get_invoice', 'description' => 'Read an invoice', 'inputSchema' => ['type' => 'object']],
                    ['name' => 'refund_payment', 'description' => 'Refund', 'inputSchema' => ['type' => 'object']],
                    ['name' => 'list_payments', 'description' => 'List', 'inputSchema' => ['type' => 'object'], 'annotations' => ['destructiveHint' => true]],
                ]],
                'tools/call' => ['content' => [['type' => 'text', 'text' => 'Invoice 42: paid $300']]],
            };

            // answer as an SSE stream, as many servers do
            return Http::response("event: message\ndata: ".json_encode(['jsonrpc' => '2.0', 'id' => $m['id'], 'result' => $result])."\n\n", 200, ['Content-Type' => 'text/event-stream', 'Mcp-Session-Id' => 'sess-1']);
        }]);

        $this->as($tok)->patchJson('/api/extensions/mcp_custom', ['settings' => ['url' => 'ftp://127.0.0.1/mcp']])->assertStatus(400);
        $v = $this->as($tok)->patchJson('/api/extensions/mcp_custom', ['settings' => ['url' => 'http://127.0.0.1/mcp'], 'token' => 'remote-secret'])->assertOk();
        $this->assertSame('ok', $v->json('status'));
        $this->assertSame([true, false, false], array_column($v->json('tools'), 'readOnly'));
        $this->assertStringNotContainsString('remote-secret', $v->getContent());
        $this->assertSame(['initialize', 'Bearer remote-secret', null], $seen[0]);
        $this->assertSame('sess-1', $seen[2][2]); // session id carried after initialize

        Platform::saveSection('ai', ['openRouterApiKey' => 'k'], 'test');
        $ctxs = [];
        Ai::$fake = function ($t, $r) use (&$ctxs) {
            if ($r['taskType'] === 'worker') {
                return ['text' => json_encode(['calls' => [['server' => 'mcp_custom', 'tool' => 'get_invoice', 'args' => ['id' => 42]], ['server' => 'mcp_custom', 'tool' => 'refund_payment']]]), 'model' => 'm', 'inputTokens' => 1, 'outputTokens' => 1];
            }
            $ctxs[] = $r['system'];

            return ['text' => $r['taskType'] === 'chat' ? 'Yes, paid.' : '{"facts":[]}', 'model' => 'm', 'inputTokens' => 1, 'outputTokens' => 1];
        };
        $r = $this->as($tok)->postJson('/api/chat/message', ['content' => 'Did the lead from invoice 42 pay?'])->assertOk();
        $this->assertContains('Custom MCP server · get_invoice', $r->json('sources'));
        $this->assertStringContainsString('Invoice 42: paid $300', $ctxs[0]);
        $this->assertNotContains('refund_payment', array_map(fn ($s) => $s[0] === 'tools/call' ? 'x' : $s[0], $seen)); // write tools never called
        $this->assertSame(1, collect($seen)->where(0, 'tools/call')->count());
    }

    public function test_lead_plugins(): void
    {
        $this->assertSame('+2348031234567', Plugins::normalizePhone('0803 123 4567', '+234'));
        $this->assertSame('+2348031234567', Plugins::normalizePhone('2348031234567', '+234'));
        $this->assertSame('+447700900123', Plugins::normalizePhone('0044 7700 900123', '+234'));
        $this->assertNull(Plugins::normalizePhone('12', '+234'));

        [$tok] = $this->owner();
        $mem = $this->teammate($tok, 'm@example.com');
        $memId = DB::table('users')->where('email', 'm@example.com')->value('id');
        $this->flushHeaders();
        $this->as($tok)->patchJson('/api/extensions/plugin_phone_normalizer', ['enabled' => true])->assertOk()->assertJsonPath('settings.defaultCountryCode', '+234');
        $this->as($tok)->patchJson('/api/extensions/plugin_duplicate_guard', ['settings' => ['windowDays' => 'abc']])->assertStatus(400);
        $this->as($tok)->patchJson('/api/extensions/plugin_duplicate_guard', ['settings' => ['windowDays' => 7]])->assertOk();
        $this->as($tok)->patchJson('/api/extensions/plugin_round_robin', ['settings' => ['memberIds' => []]])->assertStatus(400);
        $this->as($tok)->patchJson('/api/extensions/plugin_round_robin', ['settings' => ['memberIds' => [$memId, 'not-a-member']]])->assertOk()->assertJsonPath('settings.memberIds', [$memId]);

        $t = DB::table('tenants')->first();
        $a = LeadService::createLead($t, ['deploymentId' => null, 'campaignId' => null, 'sourceSystem' => 'test', 'vip' => false, 'data' => ['fullName' => 'Bola One', 'email' => 'bola@example.com', 'phone' => '0803 123 4567', 'sourceSystem' => 'test', 'sourceIdentifier' => null, 'utmSource' => null, 'utmMedium' => null, 'utmCampaign' => null, 'utmContent' => null, 'utmTerm' => null]]);
        $b = LeadService::createLead($t, ['deploymentId' => null, 'campaignId' => null, 'sourceSystem' => 'test', 'vip' => false, 'data' => ['fullName' => 'Bola Again', 'email' => 'BOLA@example.com', 'phone' => null, 'sourceSystem' => 'test', 'sourceIdentifier' => null, 'utmSource' => null, 'utmMedium' => null, 'utmCampaign' => null, 'utmContent' => null, 'utmTerm' => null]]);
        $this->assertSame('+2348031234567', DB::table('leads')->where('id', $a->id)->value('phone'));
        $this->assertSame($memId, DB::table('leads')->where('id', $a->id)->value('assignee_id'));
        $this->assertTrue(DB::table('lead_lifecycle_events')->where('lead_id', $b->id)->where('event', 'like', 'Possible duplicate of Bola One%')->exists());
        $this->assertTrue(DB::table('notifications')->where('user_id', $memId)->where('kind', 'assignment')->exists());
        $this->assertSame(2, (int) DB::table('workspace_extensions')->where('key', 'plugin_phone_normalizer')->value('usage_count'));

        // Only the owner can switch round-robin on or off
        $adm = $this->teammate($tok, 'a@example.com', 'admin');
        $this->flushHeaders();
        DB::table('users')->where('email', 'a@example.com')->update(['permissions' => json_encode(['extensions.manage' => true])]);
        $this->as($adm)->deleteJson('/api/extensions/plugin_round_robin')->assertStatus(403)->assertJsonPath('reason', 'owner_only');

        // Weekly digest: Mondays from 07:00 UTC, once per ISO week
        $this->flushHeaders();
        $this->as($tok)->patchJson('/api/extensions/plugin_weekly_digest', ['settings' => ['recipients' => 'team']])->assertOk();
        $monday = new \DateTimeImmutable('2026-11-09T08:00:00Z');
        $this->assertSame(0, Plugins::runWeeklyDigests(new \DateTimeImmutable('2026-11-10T08:00:00Z')));
        $this->assertSame(3, Plugins::runWeeklyDigests($monday));
        $this->assertSame(0, Plugins::runWeeklyDigests($monday));
    }
}
