<?php

namespace Tests\Feature;

use App\Services\CrossToolService;
use App\Services\Mailer;
use App\Services\PageService;
use App\Services\Platform;
use App\Services\SettingsService;
use App\Services\Telegram;
use App\Support\Crypto;
use App\Support\Errors;
use App\Support\Fail;
use App\Support\Sql;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

class PagesIntegrationsTest extends TestCase
{
    protected function tearDown(): void
    {
        PageService::$resolveCname = null;
        SettingsService::$probe = null;
        Telegram::$call = null;
        parent::tearDown();
    }

    /** @param array<string,string> $files */
    private function zip(array $files, string $name = 'site.zip'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $z = new ZipArchive;
        $z->open($path, ZipArchive::OVERWRITE);
        foreach ($files as $p => $c) {
            $z->addFromString($p, $c);
        }
        $z->close();

        return new UploadedFile($path, $name, 'application/zip', null, true);
    }

    private function upload(string $tok, array $files, array $extra = [])
    {
        return $this->as($tok)->post('/api/pages/upload', ['file' => $this->zip($files), ...$extra], ['Accept' => 'application/json']);
    }

    public function test_upload_serve_redeploy_rollback_pause_delete(): void
    {
        [$tok] = $this->owner();
        $r = $this->upload($tok, ['dist/index.html' => '<h1>v1</h1>', 'dist/app.css' => 'body{}', 'dist/about/index.html' => 'About'], ['name' => 'Spring Offer'])->assertOk()->assertJson(['status' => 'ready']);
        $id = $r->json('id');
        $page = $this->as($tok)->getJson("/api/pages/{$id}")->assertOk()->assertJson(['name' => 'Spring Offer', 'status' => 'ready', 'servingState' => 'active', 'entryFile' => 'index.html', 'storageSizeBytes' => 22])->json();
        $this->assertMatchesRegularExpression('/^spring-offer-[0-9a-f]{6}$/', $page['subdomain']);
        $this->assertSame(22, (int) DB::table('tenants')->value('storage_used_bytes'));
        $sub = $page['subdomain'];

        $this->flushHeaders();
        $this->rawGet("http://localhost/sites/{$sub}")->assertRedirect("/sites/{$sub}/");
        $this->rawGet("http://localhost/sites/{$sub}/")->assertOk()->assertSee('v1', false)->assertHeader('Cache-Control', 'max-age=0, no-store, private')->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");
        $this->get("/sites/{$sub}/app.css")->assertOk()->assertHeader('Content-Type', 'text/css; charset=utf-8');
        $this->get("/sites/{$sub}/about")->assertOk()->assertSee('About');
        $this->get("/sites/{$sub}/nope.js")->assertStatus(404)->assertSee('Page not found');
        $this->assertSame(2, (int) DB::table('page_visits')->value('visits'));

        $this->as($tok)->post("/api/pages/{$id}/redeploy", ['file' => $this->zip(['index.html' => '<h1>v2</h1>'])], ['Accept' => 'application/json'])->assertOk()->assertJson(['status' => 'ready']);
        $this->flushHeaders();
        $this->rawGet("http://localhost/sites/{$sub}/")->assertSee('v2', false);
        $this->as($tok)->getJson("/api/pages/{$id}")->assertJson(['hasRollbackAvailable' => true]);
        $this->as($tok)->postJson("/api/pages/{$id}/rollback")->assertOk();
        $this->flushHeaders();
        $this->rawGet("http://localhost/sites/{$sub}/")->assertSee('v1', false);

        $this->as($tok)->postJson("/api/pages/{$id}/pause")->assertOk()->assertJson(['servingState' => 'paused']);
        $this->flushHeaders();
        $this->rawGet("http://localhost/sites/{$sub}/")->assertStatus(503)->assertSee('This page is paused');
        $this->as($tok)->postJson("/api/pages/{$id}/unpause")->assertOk();

        $this->as($tok)->patchJson("/api/pages/{$id}", ['slug' => 'Black Friday!', 'vip' => true])->assertOk()->assertJson(['vip' => true])
            ->assertJsonPath('subdomain', 'black-friday-'.substr($sub, -6));
        $this->as($tok)->deleteJson("/api/pages/{$id}")->assertOk();
        $this->assertSame(0, (int) DB::table('tenants')->value('storage_used_bytes'));
        $this->as($tok)->getJson("/api/pages/{$id}")->assertStatus(404);
    }

    public function test_upload_validation(): void
    {
        [$tok] = $this->owner();
        $this->as($tok)->post('/api/pages/upload', ['file' => UploadedFile::fake()->createWithContent('x.txt', 'hi')], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('message', 'Only .zip files can be uploaded.');
        $this->as($tok)->post('/api/pages/upload', ['file' => UploadedFile::fake()->createWithContent('bad.zip', 'not a zip')], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('message', 'Invalid ZIP file. Please check and try again.');
        $this->upload($tok, ['readme.txt' => 'x'])->assertStatus(422)->assertJsonPath('message', 'No index.html found in ZIP. Ensure your build output is included.');
        $this->upload($tok, ['a.html' => 'A', 'b.html' => 'B'])->assertOk()->assertJson(['status' => 'needs_input', 'entry_points' => ['a.html', 'b.html']]);
        $this->upload($tok, ['a.html' => 'A', 'b.html' => 'B'], ['entryFile' => 'b.html'])->assertOk()->assertJson(['status' => 'ready']);
        DB::table('tenants')->update(['storage_quota_bytes' => 5]);
        $this->upload($tok, ['index.html' => 'too big for the quota'])->assertStatus(413)->assertJson(['code' => 'PAYLOAD_TOO_LARGE']);
    }

    public function test_starter_page_limit_and_member_permissions(): void
    {
        [$tok] = $this->owner('s@example.com', 'starter');
        for ($i = 0; $i < 5; $i++) {
            $this->upload($tok, ['index.html' => "p{$i}"])->assertOk();
        }
        $this->upload($tok, ['index.html' => 'six'])->assertStatus(403)->assertJson(['reason' => 'plan_limit', 'message' => 'Your plan includes 5 deployments. Additional deployments are $15/month each.']);
        $member = $this->teammate($tok, 'm@example.com');
        $this->as($member)->post('/api/pages/upload', ['file' => $this->zip(['index.html' => 'x'])], ['Accept' => 'application/json'])->assertStatus(403)->assertJson(['reason' => 'permission_denied']);
        $pages = $this->as($member)->getJson('/api/pages')->assertOk()->json('data');
        $this->assertArrayNotHasKey('secret', $pages[0]['webhook']);
    }

    public function test_host_based_serving_and_custom_domain(): void
    {
        [$tok] = $this->owner();
        config(['camplo.pages_base_domain' => 'pages.test', 'camplo.app_url' => 'https://app.test']);
        $id = $this->upload($tok, ['index.html' => 'hosted!'])->json('id');
        $sub = DB::table('deployments')->where('id', $id)->value('subdomain');
        $this->flushHeaders();
        $this->rawGet("http://{$sub}.pages.test/")->assertOk()->assertSee('hosted!');
        $this->get('http://app.test/api/health')->assertOk();

        $d = $this->as($tok)->postJson('/api/domains', ['deployment_id' => $id, 'domain_name' => 'https://Offers.Brand.com/x'])->assertOk()
            ->assertJson(['domainName' => 'offers.brand.com', 'status' => 'pending', 'record' => ['type' => 'CNAME', 'value' => "{$sub}.pages.test"]])->json();
        $this->as($tok)->postJson('/api/domains', ['deployment_id' => $id, 'domain_name' => 'offers.brand.com'])->assertStatus(409);
        $this->as($tok)->postJson('/api/domains', ['deployment_id' => $id, 'domain_name' => 'not a domain'])->assertStatus(400);
        $this->flushHeaders();
        $this->rawGet('http://offers.brand.com/')->assertOk()->assertDontSee('hosted!'); // unverified: the app answers, not the page
        PageService::$resolveCname = fn () => [];
        $this->as($tok)->postJson("/api/domains/{$d['id']}/verify")->assertOk()->assertJson(['verified' => false]);
        PageService::$resolveCname = fn ($h) => $h === 'offers.brand.com' ? ["{$sub}.pages.test."] : [];
        $this->as($tok)->postJson("/api/domains/{$d['id']}/verify")->assertOk()->assertJson(['verified' => true, 'message' => 'Verified']);
        $this->flushHeaders();
        $this->rawGet('http://offers.brand.com/')->assertOk()->assertSee('hosted!');
        $this->get('http://localhost/api/caddy/ask?domain=offers.brand.com')->assertOk();
        $this->get('http://localhost/api/caddy/ask?domain=evil.com')->assertStatus(404);
        $this->as($tok)->getJson('http://localhost/api/domains')->assertOk()->assertJsonPath('data.0.status', 'verified');
    }

    public function test_page_ingest_secret_and_webhook_selftest(): void
    {
        [$tok] = $this->owner();
        $id = $this->upload($tok, ['index.html' => 'x'])->json('id');
        $page = $this->as($tok)->getJson("/api/pages/{$id}")->json();
        $this->assertSame('never_connected', $page['webhook']['state']);
        $this->as($tok)->postJson("/api/pages/{$id}/webhook/test")->assertOk()->assertJson(['ok' => true]);
        $raw = json_encode(['name' => 'From page']);
        $this->call('POST', parse_url($page['webhook']['url'], PHP_URL_PATH), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CAMPLO_SIGNATURE' => 'sha256='.hash_hmac('sha256', $raw, $page['webhook']['secret'])], $raw)->assertStatus(201);
        $this->as($tok)->getJson("/api/pages/{$id}/webhook-health")->assertJson(['state' => 'operational']);
    }

    public function test_integrations_connect_verify_disconnect_and_tool_limit(): void
    {
        [$tok] = $this->owner();
        $list = $this->as($tok)->getJson('/api/integrations?limit=100')->assertOk()->json('data');
        $this->assertCount(22, $list);
        $this->as($tok)->postJson('/api/integrations/brevo/connect', [])->assertStatus(400)->assertJsonPath('message', 'Brevo: API key (v3) is required.');
        $b = $this->as($tok)->postJson('/api/integrations/brevo/connect', ['apiKey' => 'xkeysib-secret-1234'])->assertOk()->assertJson(['status' => 'connected', 'connectionMethod' => 'api_key'])->json();
        $this->assertSame(str_repeat('•', 12).'1234', $b['apiKeyMasked']);
        $this->assertStringStartsWith('integration.', DB::table('integrations')->value('api_key_encrypted'));
        // Growth includes one connected tool
        $this->as($tok)->postJson('/api/integrations/twenty_crm/connect', ['fields' => ['baseUrl' => 'https://crm.example.com', 'apiKey' => 'k']])->assertStatus(403)->assertJson(['reason' => 'plan_limit', 'required_plan' => 'watchtower']);
        SettingsService::$probe = fn () => ['ok' => false, 'message' => 'The provider answered 401. Check the values and try again.'];
        $this->as($tok)->postJson('/api/integrations/brevo/verify')->assertOk()->assertJson(['ok' => false, 'status' => 'failed']);
        $this->as($tok)->deleteJson('/api/integrations/brevo')->assertOk();
        $this->assertNull(DB::table('integrations')->value('api_key_encrypted'));
        $this->as($tok)->postJson('/api/integrations/nope/connect', [])->assertStatus(400);

        // A lead source gets its own signed inbound webhook.
        $tally = $this->as($tok)->postJson('/api/integrations/tally/connect', [])->assertOk()->json();
        $this->assertStringContainsString('/api/v1/hooks/', $tally['webhooks']['leads']);
        $this->assertNotEmpty($tally['webhooks']['signingSecret']);
        $raw = json_encode(['data' => ['fields' => [['label' => 'Name', 'value' => 'Via Tally']]]]);
        $this->call('POST', parse_url($tally['webhooks']['leadsBase'], PHP_URL_PATH), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_TALLY_SIGNATURE' => base64_encode(hash_hmac('sha256', $raw, $tally['webhooks']['signingSecret'], true))], $raw)->assertStatus(201);
        $this->assertSame('Tally', DB::table('leads')->value('source_system'));
    }

    public function test_lifecycle_webhook_and_cross_tool_sla(): void
    {
        [$tok] = $this->owner('w@example.com', 'watchtower');
        $tid = $this->tenantId('w@example.com');
        $twenty = $this->as($tok)->postJson('/api/integrations/twenty_crm/connect', ['fields' => ['baseUrl' => 'https://crm.example.com', 'apiKey' => 'twenty-key']])->assertOk()->json();
        $life = parse_url($twenty['webhooks']['lifecycle'], PHP_URL_PATH);
        DB::table('integrations')->update(['created_at' => now()->subDays(3)]);
        $lead = Sql::insertId('leads', ['tenant_id' => $tid, 'full_name' => 'Slow CRM', 'source_system' => 'webhook', 'email' => 'slow@x.test', 'received_at' => now()->subDays(2), 'status' => 'responded', 'responded_at' => now()->subHours(40)]);
        $this->postJson($life, ['email' => 'slow@x.test', 'contact_id' => 'c-1', 'event' => 'Person created'])->assertStatus(201);
        $this->postJson($life, ['email' => 'unknown@x.test', 'event' => 'x'])->assertStatus(202);
        $this->postJson($life, ['email' => 'slow@x.test'])->assertStatus(422);
        $this->postJson(substr($life, 0, -3).'bad', ['event' => 'x'])->assertStatus(404);
        $rules = $this->as($tok)->getJson('/api/sla/config/cross-tool')->assertOk()->json('data.0.rules');
        $this->assertSame('not_contacted', $rules[0]['ruleType']);

        $this->artisan('camplo:sweep')->assertSuccessful();
        $b = $this->as($tok)->getJson('/api/sla/cross-tool')->assertOk()->assertJson(['connected' => true])->json('data.0');
        $this->assertSame(['not_contacted', 'Slow CRM', 16], [$b['breachType'], $b['leadName'], $b['hoursExceeded']]);
        $this->assertSame(1, DB::table('insights')->where('dedupe_key', 'like', 'xtool:%')->count());
        // Evidence arrives → the breach resolves on the next check.
        $this->postJson($life, ['contact_id' => 'c-1', 'event' => 'Contacted by phone'])->assertStatus(201);
        CrossToolService::check();
        $this->as($tok)->getJson('/api/sla/cross-tool')->assertJsonCount(0, 'data');
    }

    public function test_inbound_outbound_webhooks_and_signed_delivery(): void
    {
        [$tok] = $this->owner();
        $in = $this->as($tok)->postJson('/api/webhooks/inbound', ['sourceLabel' => 'Make'])->assertOk()->json();
        $this->assertNotEmpty($in['secret']);
        $this->as($tok)->getJson('/api/webhooks/inbound')->assertOk()->assertJsonMissingPath('data.0.secret')->assertJsonPath('data.0.sourceLabel', 'Make');
        $this->as($tok)->postJson('/api/webhooks/outbound', ['destinationUrl' => 'http://localhost/hook', 'eventTrigger' => 'lead.received'])->assertStatus(400);
        $this->as($tok)->postJson('/api/webhooks/outbound', ['destinationUrl' => 'https://hooks.example.com/x', 'eventTrigger' => 'nope'])->assertStatus(400);
        $this->as($tok)->postJson('/api/webhooks/outbound', ['destinationUrl' => 'https://hooks.example.com/x', 'eventTrigger' => 'lead.received', 'secret' => 'out-secret'])->assertOk();
        Http::fake(['hooks.example.com/*' => Http::response('ok', 200)]);
        $this->postJson(parse_url($in['url'], PHP_URL_PATH).'?token='.$in['secret'], ['name' => 'Outbound Test'])->assertStatus(201);
        Http::assertSent(function ($req) {
            $body = $req->body();

            return $req->url() === 'https://hooks.example.com/x' && $req->header('X-Camplo-Event')[0] === 'lead.received'
                && $req->header('X-Camplo-Signature')[0] === 'sha256='.hash_hmac('sha256', $body, 'out-secret') && json_decode($body, true)['data']['name'] === 'Outbound Test';
        });
        $this->assertNotNull(DB::table('outbound_webhooks')->value('last_sent_at'));
        $this->as($tok)->getJson('/api/webhooks/outbound')->assertJsonPath('data.0.signed', true);
    }

    public function test_ai_provider_settings(): void
    {
        [$tok] = $this->owner();
        $this->as($tok)->getJson('/api/settings/ai-provider')->assertOk()->assertJson(['primary' => ['provider' => null], 'refreshIntervalMinutes' => 30, 'memory' => ['engine' => 'built_in', 'total' => 0]]);
        $this->as($tok)->patchJson('/api/settings/ai-provider', ['primary' => ['provider' => 'anthropic', 'modelName' => ' claude-x ', 'apiKey' => 'sk-ant-secret-9999'], 'refreshIntervalMinutes' => 10, 'eventTriggers' => ['sla_breach', 'bogus']])->assertOk()
            ->assertJson(['primary' => ['provider' => 'anthropic', 'modelName' => 'claude-x', 'apiKeyMasked' => str_repeat('•', 12).'9999', 'status' => 'not_connected'], 'eventTriggers' => ['sla_breach']]);
        $this->assertStringStartsWith('ai.', DB::table('ai_provider_configs')->value('primary_api_key_encrypted'));
        $this->as($tok)->patchJson('/api/settings/ai-provider', ['primary' => ['provider' => 'skynet']])->assertStatus(400);
    }

    public function test_telegram_connect_link_and_acknowledge_button(): void
    {
        [$tok] = $this->owner();
        $tid = $this->tenantId();
        $uid = DB::table('users')->value('id');
        $calls = [];
        Telegram::$call = function ($token, $method, $body) use (&$calls) {
            $calls[] = [$method, $body];

            return $method === 'getMe' ? ['ok' => true, 'result' => ['username' => 'camplo_test_bot']] : ['ok' => true];
        };
        $this->as($tok)->postJson('/api/settings/telegram/verify', ['botToken' => '123456:ABCDEF-token'])->assertOk()->assertJson(['ok' => true, 'botUsername' => 'camplo_test_bot']);
        $this->assertSame('setWebhook', $calls[1][0]);
        $secret = $calls[1][1]['secret_token'];
        $this->as($tok)->getJson('/api/settings/telegram')->assertOk()->assertJson(['connected' => true, 'botUsername' => 'camplo_test_bot', 'linkCode' => str_replace('-', '', $uid)]);

        $this->postJson("/api/telegram/{$tid}", ['message' => ['text' => '/start '.str_replace('-', '', $uid), 'chat' => ['id' => 777]]])->assertStatus(401);
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $secret)->postJson("/api/telegram/{$tid}", ['message' => ['text' => '/start '.str_replace('-', '', $uid), 'chat' => ['id' => 777]]])->assertOk();
        $this->assertSame('777', DB::table('users')->value('telegram_chat_id'));

        // A new lead goes to the linked member with an Acknowledge button…
        $lead = Sql::insertId('leads', ['tenant_id' => $tid, 'full_name' => 'TG Lead', 'source_system' => 'webhook', 'received_at' => now()]);
        Telegram::notifyNewLead($tid, $lead);
        $msg = collect($calls)->last(fn ($c) => $c[0] === 'sendMessage');
        $data = $msg[1]['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        $this->assertSame(Telegram::ackCallbackData($lead, $uid, $tid), $data);
        // …and pressing it responds to the lead.
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $secret)->postJson("/api/telegram/{$tid}", ['callback_query' => ['id' => 'cb1', 'data' => $data, 'from' => ['id' => 777], 'message' => ['chat' => ['id' => 777], 'message_id' => 5]]])->assertOk();
        $this->assertSame('responded', DB::table('leads')->where('id', $lead)->value('status'));
        $this->assertSame('✓ Olu Owner acknowledged TG Lead.', collect($calls)->last(fn ($c) => $c[0] === 'editMessageText')[1]['text']);
        // A forged button does nothing.
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $secret)->postJson("/api/telegram/{$tid}", ['callback_query' => ['id' => 'cb2', 'data' => 'ack:'.str_replace('-', '', $lead).':0000000000000000', 'from' => ['id' => 777], 'message' => ['chat' => ['id' => 777], 'message_id' => 6]]]);
        $this->assertSame('This acknowledgment is no longer valid.', collect($calls)->last(fn ($c) => $c[0] === 'editMessageText')[1]['text']);
    }

    public function test_sla_alert_prefers_telegram_and_falls_back_to_email(): void
    {
        [$tok] = $this->owner();
        $tid = $this->tenantId();
        DB::table('telegram_connections')->insert(['tenant_id' => $tid, 'bot_token_encrypted' => Crypto::encrypt('123:abc', 'telegram'), 'verified' => true]);
        DB::table('users')->update(['telegram_chat_id' => '777', 'notify_channel' => 'telegram']);
        DB::table('leads')->insert(['tenant_id' => $tid, 'full_name' => 'Waiting', 'source_system' => 'webhook', 'received_at' => now()->subHours(6)]);
        Telegram::$call = fn () => ['ok' => true];
        $this->artisan('camplo:sweep');
        $this->assertCount(0, array_filter(Mailer::$outbox, fn ($m) => str_starts_with($m['subject'], 'Urgent')));
        DB::table('leads')->update(['sla_alert_sent_at' => null]);
        Telegram::$call = fn () => ['ok' => false];
        $this->artisan('camplo:sweep');
        $this->assertCount(1, array_filter(Mailer::$outbox, fn ($m) => str_starts_with($m['subject'], 'Urgent')));
    }

    public function test_polar_webhook(): void
    {
        $this->owner();
        $tid = $this->tenantId();
        $secret = base64_encode('polar-webhook-secret-bytes');
        config(['camplo.polar_webhook_secret' => "whsec_{$secret}", 'camplo.polar_product_ids.watchtower' => 'prod_watch']);
        Platform::invalidate();
        $send = function (array $evt, ?string $sig = null) use ($secret) {
            $raw = json_encode($evt);
            $id = 'msg_1';
            $ts = (string) time();
            $sig ??= 'v1,'.base64_encode(hash_hmac('sha256', "{$id}.{$ts}.{$raw}", base64_decode($secret), true));

            return $this->call('POST', '/api/polar/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_WEBHOOK_ID' => $id, 'HTTP_WEBHOOK_TIMESTAMP' => $ts, 'HTTP_WEBHOOK_SIGNATURE' => $sig], $raw);
        };
        $send(['type' => 'subscription.active', 'data' => []], 'v1,forged')->assertStatus(401);
        $send(['type' => 'subscription.active', 'data' => ['id' => 'sub_9', 'product_id' => 'prod_watch', 'metadata' => ['tenant_id' => $tid]]])->assertOk()->assertJson(['handled' => true]);
        $this->assertSame(['watchtower', 'sub_9'], [DB::table('tenants')->value('plan'), DB::table('tenants')->value('polar_subscription_id')]);
        $send(['type' => 'order.created', 'data' => ['id' => 'ord_1', 'amount' => 34700, 'customer' => ['email' => 'OWNER@example.com']]])->assertOk();
        $this->assertSame('We received your payment of $347.00. Your account is under review and is usually activated within one business day.', end(Mailer::$outbox)['text']);
        $send(['type' => 'subscription.revoked', 'data' => ['metadata' => ['tenant_id' => $tid]]])->assertOk();
        $this->assertSame('suspended', DB::table('tenants')->value('status'));
    }

    public function test_error_monitoring(): void
    {
        $this->postJson('/api/monitor/client-error', ['message' => ''])->assertStatus(400);
        $this->postJson('/api/monitor/client-error', ['message' => 'TypeError: x is undefined (id 3f2a1c9e-1111-2222-3333-444455556666)', 'url' => 'https://app.test/#/leads?x=1', 'stack' => 'at app.js:1'])->assertNoContent();
        $this->postJson('/api/monitor/client-error', ['message' => 'TypeError: x is undefined (id 9f2a1c9e-1111-2222-3333-444455556666)', 'url' => 'https://app.test/#/leads'])->assertNoContent();
        $e = DB::table('error_events')->first();
        $this->assertSame(['client', 2, '/#/leads'], [$e->source, (int) $e->count, $e->route]);

        // Unexpected exceptions are reported into error_events; expected API errors are not.
        app(ExceptionHandler::class)->report(new \RuntimeException('Kaboom 42'));
        app(ExceptionHandler::class)->report(Fail::notFound());
        $this->assertSame(1, DB::table('error_events')->where('source', 'server')->count());
        $this->assertSame('Kaboom 42', DB::table('error_events')->where('source', 'server')->value('message'));
        $this->assertSame(['url' => 'https://o1.ingest.sentry.io/api/456/envelope/', 'auth' => 'Sentry sentry_version=7, sentry_key=abc, sentry_client=camplo/1.0'], Errors::sentryTarget('https://abc@o1.ingest.sentry.io/456'));
    }
}
