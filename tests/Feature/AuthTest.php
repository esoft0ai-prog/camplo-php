<?php

namespace Tests\Feature;

use App\Services\Mailer;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthTest extends TestCase
{
    public function test_health(): void
    {
        $this->getJson('/api/health')->assertOk()->assertJson(['ok' => true, 'database' => 'postgres']);
    }

    public function test_signup_login_me_and_cookies(): void
    {
        $this->postJson('/api/auth/signup', ['name' => 'Ada Lovelace', 'email' => 'Ada@Example.com', 'password' => 'password123', 'workspaceName' => 'Analytical'])
            ->assertOk()->assertJson(['next' => '/login', 'checkoutUrl' => null]);
        $t = DB::table('tenants')->first();
        $this->assertSame('growth', $t->plan);
        $this->assertSame('active', $t->status);
        $this->assertSame('ada@example.com', $t->owner_email);
        $this->assertTrue(DB::table('ai_provider_configs')->where('tenant_id', $t->id)->exists());

        $r = $this->postJson('/api/auth/login', ['email' => 'ada@example.com', 'password' => 'password123'])->assertOk()
            ->assertJsonPath('next', '/setup')->assertJsonPath('user.initials', 'AL')->assertJsonPath('user.role', 'owner')
            ->assertJsonPath('tenant.name', 'Analytical');
        $this->assertTrue($r->json('user.permissions')['pages.delete']);
        [$at, $rt] = $r->headers->getCookies();
        $this->assertSame(['camplo_at', '/', true, 'lax', false, 900], [$at->getName(), $at->getPath(), $at->isHttpOnly(), $at->getSameSite(), $at->isSecure(), $at->getMaxAge()]);
        $this->assertSame(['camplo_rt', 2592000], [$rt->getName(), $rt->getMaxAge()]);
        $this->assertSame('{}', json_encode($r->json('user.permissionOverrides') ?: new \stdClass));

        $this->withHeader('Cookie', 'camplo_at='.rawurlencode($this->cookieValue($r, 'camplo_at')))
            ->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.email', 'ada@example.com')->assertJsonPath('next', '/setup');
    }

    public function test_duplicate_signup_is_conflict(): void
    {
        $this->owner();
        $this->postJson('/api/auth/signup', ['name' => 'X', 'email' => 'owner@example.com', 'password' => 'password123', 'workspaceName' => 'Y'])
            ->assertStatus(409)->assertJson(['code' => 'CONFLICT', 'reason' => 'duplicate_email', 'message' => 'An account with this email already exists.']);
    }

    public function test_pending_activation_when_auto_activate_off(): void
    {
        config(['camplo.auto_activate' => false]);
        $this->postJson('/api/auth/signup', ['name' => 'P', 'email' => 'p@example.com', 'password' => 'password123', 'workspaceName' => 'P'])->assertJson(['next' => '/pending']);
        $r = $this->postJson('/api/auth/login', ['email' => 'p@example.com', 'password' => 'password123'])->assertOk()->assertJson(['next' => '/pending']);
        $this->as($r->json('accessToken'))->getJson('/api/workspace')->assertStatus(403)->assertJsonPath('data.reason', 'pending_activation');
    }

    public function test_bad_login_and_throttle(): void
    {
        $this->owner();
        $this->postJson('/api/auth/login', ['email' => 'owner@example.com', 'password' => 'nope'])
            ->assertStatus(401)->assertJson(['defined' => false, 'code' => 'UNAUTHORIZED', 'status' => 401, 'message' => 'Incorrect email or password.']);
        for ($i = 0; $i < 9; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'owner@example.com', 'password' => 'nope']);
        }
        $this->postJson('/api/auth/login', ['email' => 'owner@example.com', 'password' => 'password123'])->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');
    }

    public function test_validation_error_shape(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'not-an-email'])->assertStatus(400)->assertJsonPath('code', 'BAD_REQUEST');
    }

    public function test_unauthenticated_and_unknown_routes(): void
    {
        $this->getJson('/api/workspace')->assertStatus(401)->assertJson(['code' => 'UNAUTHORIZED', 'message' => 'Session expired. Please log in again.']);
        $this->getJson('/api/nope')->assertStatus(404)->assertJson(['code' => 'NOT_FOUND']);
        $this->as('garbage')->getJson('/api/workspace')->assertStatus(401);
    }

    public function test_suspended_workspace(): void
    {
        [$tok] = $this->owner();
        DB::table('tenants')->update(['status' => 'suspended']);
        $this->as($tok)->getJson('/api/workspace')->assertStatus(403)->assertJsonPath('data.reason', 'account_suspended')->assertJsonPath('reason', 'account_suspended');
        $this->flushHeaders();
        $this->postJson('/api/auth/login', ['email' => 'owner@example.com', 'password' => 'password123'])->assertStatus(403)->assertJsonPath('reason', 'account_suspended');
    }

    public function test_refresh_rotates_and_logout_revokes(): void
    {
        $this->owner();
        $r = $this->postJson('/api/auth/login', ['email' => 'owner@example.com', 'password' => 'password123']);
        $rt = $this->cookieValue($r, 'camplo_rt');
        $r2 = $this->withHeader('Cookie', "camplo_rt={$rt}")->postJson('/api/auth/refresh')->assertOk();
        $this->assertNotEmpty($r2->json('accessToken'));
        $rt2 = $this->cookieValue($r2, 'camplo_rt');
        $this->assertNotSame($rt, $rt2);
        // the old refresh token no longer works
        $this->withHeader('Cookie', "camplo_rt={$rt}")->postJson('/api/auth/refresh')->assertStatus(401);
        $out = $this->withHeader('Cookie', "camplo_rt={$rt2}")->postJson('/api/auth/logout')->assertOk();
        $this->assertTrue($out->headers->getCookies()[0]->isCleared());
        $this->withHeader('Cookie', "camplo_rt={$rt2}")->postJson('/api/auth/refresh')->assertStatus(401);
    }

    public function test_magic_link_works_once(): void
    {
        $this->owner();
        $this->postJson('/api/auth/magic-link', ['email' => 'owner@example.com'])->assertOk()->assertJson(['ok' => true]);
        $this->postJson('/api/auth/magic-link', ['email' => 'nobody@example.com'])->assertOk();
        $mail = collect(Mailer::$outbox)->firstWhere('subject', 'Your Camplo sign-in link');
        preg_match('/token=([A-Za-z0-9_-]+)/', $mail['text'], $m);
        $this->getJson('/api/auth/verify?token='.$m[1])->assertOk()->assertJsonPath('user.email', 'owner@example.com');
        $this->getJson('/api/auth/verify?token='.$m[1])->assertStatus(401);
    }

    public function test_forgot_and_reset_password(): void
    {
        $this->owner();
        $this->postJson('/api/auth/forgot-password', ['email' => 'owner@example.com'])->assertOk();
        preg_match('/token=([A-Za-z0-9_-]+)/', Mailer::$outbox[array_key_last(Mailer::$outbox)]['text'], $m);
        $this->postJson('/api/auth/reset-password', ['token' => $m[1], 'newPassword' => 'brand-new-pass'])->assertOk();
        $this->postJson('/api/auth/reset-password', ['token' => $m[1], 'newPassword' => 'brand-new-pass'])->assertStatus(400);
        $this->postJson('/api/auth/login', ['email' => 'owner@example.com', 'password' => 'brand-new-pass'])->assertOk();
    }

    public function test_login_accepts_bcryptjs_hashes_from_the_node_app(): void
    {
        $this->owner();
        $hash = json_decode((string) file_get_contents(base_path('tests/fixtures/node-jwt.json')), true)['bcrypt'];
        DB::table('users')->update(['password_hash' => $hash]);
        $this->postJson('/api/auth/login', ['email' => 'owner@example.com', 'password' => 'camplo-demo'])->assertOk();
    }

    public function test_update_me(): void
    {
        [$tok] = $this->owner();
        $this->as($tok)->patchJson('/api/auth/me', ['name' => '  Olu New ', 'theme' => 'light'])->assertOk()->assertJson(['name' => 'Olu New', 'theme' => 'light', 'initials' => 'ON']);
        $this->as($tok)->patchJson('/api/auth/me', ['theme' => 'pink'])->assertStatus(400);
    }

    public function test_spa_shell_and_public_platform(): void
    {
        $this->get('/dashboard')->assertOk()->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $this->getJson('/api/public/platform')->assertOk()->assertJsonPath('prices.growth', 197)->assertJsonPath('limits.growth.deployments', -1);
    }
}
