<?php

namespace Tests\Feature;

use App\Services\AdminService;
use App\Services\Mailer;
use App\Support\Errors;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminTest extends TestCase
{
    private const SECRET = 'JBSWY3DPEHPK3PXP';

    protected function setUp(): void
    {
        parent::setUp();
        config(['camplo.super_admin_email' => 'root@camplo.test', 'camplo.super_admin_password' => 'correct horse', 'camplo.admin_totp_secret' => self::SECRET]);
    }

    private function adminToken(): string
    {
        return $this->postJson('/api/admin/api/login', ['email' => 'root@camplo.test', 'password' => 'correct horse', 'code' => AdminService::totp(self::SECRET)])->assertOk()->json('token');
    }

    public function test_totp_matches_rfc6238(): void
    {
        // RFC 6238 SHA-1 vector: secret "12345678901234567890", T = 59 s → 94287082 (last 6 digits).
        $this->assertSame('287082', AdminService::totp('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 59_000));
        $this->assertSame('081804', AdminService::totp('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 1111111109_000));
    }

    public function test_login_requires_password_and_code(): void
    {
        $this->postJson('/api/admin/api/login', ['email' => 'root@camplo.test', 'password' => 'wrong', 'code' => AdminService::totp(self::SECRET)])->assertStatus(401);
        $this->postJson('/api/admin/api/login', ['email' => 'root@camplo.test', 'password' => 'correct horse', 'code' => '000000'])->assertStatus(401);
        $this->postJson('/api/admin/api/login', ['email' => 'root@camplo.test', 'password' => 'correct horse', 'code' => '12'])->assertStatus(400);
        $tok = $this->adminToken();
        $this->assertSame(1, DB::table('super_admins')->count());
        $this->getJson('/api/admin/api/health')->assertStatus(401);
        $this->withHeader('Authorization', "Bearer {$tok}")->getJson('/api/admin/api/health')->assertOk()->assertJsonStructure(['leadsIngestedToday', 'ingestionErrorRate24h', 'staleSources']);

        // A tenant access token is not an admin token
        $this->flushHeaders();
        [$owner] = $this->owner();
        $this->flushHeaders();
        $this->withHeader('Authorization', "Bearer {$owner}")->getJson('/api/admin/api/accounts')->assertStatus(401);
    }

    public function test_accounts_status_update_and_audit(): void
    {
        [$owner] = $this->owner();
        $tid = $this->tenantId();
        $this->flushHeaders();
        $a = $this->as($this->adminToken());
        $list = $a->getJson('/api/admin/api/accounts')->assertOk();
        $this->assertSame('Acme', $list->json('data.0.businessName'));

        $a->postJson("/api/admin/api/accounts/{$tid}/suspend")->assertOk()->assertJsonPath('status', 'suspended');
        $this->flushHeaders();
        $this->as($owner)->getJson('/api/auth/me')->assertStatus(403);
        DB::table('deployments')->insert(['tenant_id' => $tid, 'name' => 'P', 'status' => 'deleted', 'failure_reason' => 'suspended', 'subdomain' => 'p-x', 'storage_path' => 'x', 'webhook_secret_encrypted' => 'x']);
        $a = $this->as($this->adminToken());
        $a->postJson("/api/admin/api/accounts/{$tid}/activate")->assertOk();
        $this->assertSame('ready', DB::table('deployments')->value('status'));
        $this->assertTrue(collect(Mailer::$outbox)->contains(fn ($m) => $m['to'] === 'owner@example.com'));

        $d = $a->patchJson("/api/admin/api/accounts/{$tid}", ['plan' => 'watchtower', 'monthlyFee' => 150, 'ownerEmail' => 'NEW@example.com', 'slaThresholdMinutes' => 30])->assertOk();
        $this->assertSame('watchtower', $d->json('plan'));
        $this->assertSame(150, $d->json('monthlyFee'));
        $this->assertSame('new@example.com', DB::table('users')->where('role', 'owner')->value('email'));
        $this->assertStringContainsString('plan growth → watchtower', implode(' | ', array_filter(array_column($d->json('history'), 'note'))));
        $a->postJson("/api/admin/api/accounts/{$tid}/note", ['note' => 'Called the owner'])->assertOk();
        $a->patchJson("/api/admin/api/accounts/{$tid}/pricing", ['monthlyFee' => 99.5])->assertOk();
        $this->assertSame(99.5, $a->getJson("/api/admin/api/accounts/{$tid}")->json('monthlyFee'));
        $a->getJson('/api/admin/api/accounts/00000000-0000-0000-0000-000000000000')->assertStatus(404);
    }

    public function test_platform_errors_and_extension_catalog(): void
    {
        $a = $this->as($this->adminToken());
        $p = $a->patchJson('/api/admin/api/platform/branding', ['values' => ['productName' => 'Leadly', 'bogus' => 1]])->assertOk();
        $this->assertSame('Leadly', $p->json('sections.branding.values.productName'));
        $this->assertArrayNotHasKey('bogus', $p->json('sections.branding.values'));
        $a->patchJson('/api/admin/api/platform/branding', ['values' => ['productName' => ['x']]])->assertStatus(400);
        $a->patchJson('/api/admin/api/platform/nope', ['values' => []])->assertStatus(400);
        $p = $a->patchJson('/api/admin/api/platform/ai', ['values' => ['openRouterApiKey' => 'sk-or-secret-1234']])->assertOk();
        $this->assertTrue($p->json('sections.ai.secrets.openRouterApiKey.set'));
        $this->assertStringNotContainsString('sk-or-secret-1234', $p->getContent());
        $hist = $a->getJson('/api/admin/api/platform')->json('history');
        $this->assertContains('Updated openRouterApiKey (secret updated)', array_column($hist, 'note'));

        Errors::capture(new \RuntimeException('Boom in test'), ['source' => 'server', 'route' => 'GET /x']);
        $e = $a->getJson('/api/admin/api/errors')->assertOk();
        $this->assertSame(1, $e->json('summary.open'));
        $this->assertSame('Boom in test', $e->json('data.0.message'));
        $id = $e->json('data.0.id');
        $a->postJson("/api/admin/api/errors/{$id}/resolve")->assertOk()->assertJsonPath('message', 'Boom in test');
        $this->assertSame(0, $a->getJson('/api/admin/api/errors')->json('summary.open'));
        $this->assertSame(1, count($a->getJson('/api/admin/api/errors?status=resolved')->json('data')));

        // Extensions: availability/plan overrides, custom skills, built-ins can't be deleted
        $a->patchJson('/api/admin/api/extensions/skill_speed_coach', ['kind' => 'skill', 'name' => 'Speed coach', 'available' => false, 'minPlan' => 'growth'])->assertOk()->assertJsonPath('available', false);
        $a->patchJson('/api/admin/api/extensions/skill_onboarding', ['kind' => 'skill', 'name' => 'Onboarding', 'available' => true, 'minPlan' => 'starter', 'config' => ['instructions' => 'Help.', 'triggers' => 'Onboard, setup ']])
            ->assertOk()->assertJsonPath('config.triggers', ['onboard', 'setup'])->assertJsonPath('custom', true);
        $a->patchJson('/api/admin/api/extensions/skill_empty', ['kind' => 'skill', 'name' => 'Empty', 'available' => true, 'minPlan' => 'starter', 'config' => ['instructions' => '']])->assertStatus(400);
        $a->patchJson('/api/admin/api/extensions/plugin_new', ['kind' => 'plugin', 'name' => 'X', 'available' => true, 'minPlan' => 'starter'])->assertStatus(400);
        $a->patchJson('/api/admin/api/extensions/mcp_bad', ['kind' => 'mcp_server', 'name' => 'Bad', 'available' => true, 'minPlan' => 'starter', 'config' => ['url' => 'https://10.0.0.5/mcp']])->assertStatus(400);
        $cat = collect($a->getJson('/api/admin/api/extensions')->assertOk()->json())->keyBy('key');
        $this->assertFalse($cat['skill_speed_coach']['available']);
        $this->assertSame(0, $cat['skill_onboarding']['workspaces']);
        $a->deleteJson('/api/admin/api/extensions/skill_cpl_diagnostician')->assertStatus(400);
        $a->deleteJson('/api/admin/api/extensions/skill_onboarding')->assertOk();

        // workspaces no longer see the unavailable skill
        $this->flushHeaders();
        [$owner] = $this->owner();
        $keys = array_column($this->as($owner)->getJson('/api/extensions')->json('items'), 'key');
        $this->assertNotContains('skill_speed_coach', $keys);
        $this->assertNotContains('skill_onboarding', $keys);
    }
}
