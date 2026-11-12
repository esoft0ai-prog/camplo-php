<?php

namespace Tests\Feature;

use App\Services\Mailer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    public function test_get_and_patch_workspace(): void
    {
        [$tok] = $this->owner();
        $this->as($tok)->getJson('/api/workspace')->assertOk()
            ->assertJson(['name' => 'Acme', 'plan' => 'growth', 'price' => 197, 'notifications' => ['unreadCount' => 0], 'avgConversionRate' => null])
            ->assertJsonPath('limits.campaigns', 10)->assertJsonPath('limits.deployments', null)->assertJsonPath('me.role', 'owner');
        $this->as($tok)->patchJson('/api/workspace', ['name' => 'Acme Two', 'setupComplete' => true, 'teamSize' => '2-5'])->assertOk()
            ->assertJson(['name' => 'Acme Two', 'setupComplete' => true, 'teamSize' => '2-5']);
        $this->as($tok)->getJson('/api/auth/me')->assertJsonPath('next', '/dashboard');
    }

    public function test_plan_and_subscription(): void
    {
        [$tok] = $this->owner();
        $this->as($tok)->getJson('/api/workspace/plan')->assertOk()->assertJson(['plan' => 'growth', 'aiUsage' => ['deepPercent' => 0, 'warning' => false, 'limitReached' => false]]);
        $s = $this->as($tok)->getJson('/api/workspace/subscription')->assertOk()->assertJson(['plan' => 'growth', 'monthlyFee' => 197, 'managedByPolar' => false, 'usage' => ['members' => 1]]);
        $this->assertSame(['starter', 'growth', 'watchtower', 'agency'], array_column($s->json('plans'), 'plan'));
        $this->assertFalse($s->json('plans.3.available'));
    }

    public function test_upgrade_rules(): void
    {
        [$tok] = $this->owner();
        $this->as($tok)->postJson('/api/workspace/upgrade', ['targetPlan' => 'growth'])->assertStatus(400)->assertJsonPath('message', "You're already on growth.");
        $this->as($tok)->postJson('/api/workspace/upgrade', ['targetPlan' => 'watchtower'])->assertStatus(400)->assertJsonPath('message', 'Billing is not configured for this environment.');
        config(['camplo.allow_direct_plan_change' => true]);
        \App\Services\Platform::invalidate();
        $this->as($tok)->postJson('/api/workspace/upgrade', ['targetPlan' => 'watchtower'])->assertOk()->assertJson(['applied' => true]);
        // downgrade blocked while over the target plan's limits
        for ($i = 0; $i < 4; $i++) {
            DB::table('campaigns')->insert(['tenant_id' => $this->tenantId(), 'name' => "C{$i}", 'owner_id' => DB::table('users')->value('id'), 'start_date' => '2026-01-01']);
        }
        $this->as($tok)->postJson('/api/workspace/upgrade', ['targetPlan' => 'starter'])->assertStatus(409)
            ->assertJson(['reason' => 'over_plan_limits', 'message' => 'Before moving to starter, reduce: 4 active campaigns (plan allows 3).']);
    }

    public function test_settings_owner_and_admin_only(): void
    {
        [$tok] = $this->owner();
        $this->as($tok)->getJson('/api/settings')->assertOk()->assertJsonPath('alerts.daily_summary_time', '08:00')->assertJsonPath('sla.sla_threshold_minutes', 240);
        $this->as($tok)->patchJson('/api/settings', ['sla_threshold_minutes' => 45, 'daily_summary_time' => '07:30'])->assertOk()
            ->assertJsonPath('sla.sla_threshold_minutes', 45)->assertJsonPath('alerts.daily_summary_time', '07:30');
        $this->as($tok)->patchJson('/api/settings', ['sla_threshold_minutes' => 0])->assertStatus(400);
        $member = $this->teammate($tok, 'm@example.com');
        $this->as($member)->getJson('/api/settings')->assertStatus(403)->assertJson(['code' => 'FORBIDDEN', 'message' => "You don't have access to this."]);
    }

    public function test_notification_settings(): void
    {
        [$tok] = $this->owner();
        $this->as($tok)->getJson('/api/settings/notifications')->assertOk()->assertJson(['dailyDigest' => true, 'dailyDigestTime' => '08:00', 'slaBreachAlerts' => true, 'slaBreachChannel' => 'both']);
        $this->as($tok)->patchJson('/api/settings/notifications', ['slaBreachChannel' => 'email', 'earlyWarning' => false, 'dailyDigestTime' => '09:15'])->assertOk()
            ->assertJson(['slaBreachChannel' => 'email', 'earlyWarning' => false, 'dailyDigestTime' => '09:15']);
        $this->as($tok)->patchJson('/api/settings/notifications', ['dailyDigestTime' => '9am'])->assertStatus(400)->assertJsonPath('message', 'Use HH:MM (UTC).');
    }

    public function test_invite_accept_and_permissions(): void
    {
        [$tok] = $this->owner();
        $inv = $this->as($tok)->postJson('/api/team/invitations', ['email' => 'New@Example.com', 'role' => 'admin'])->assertOk()->assertJsonPath('email', 'new@example.com');
        $this->assertStringContainsString("You've been invited to Acme on Camplo", end(Mailer::$outbox)['subject']);
        $this->as($tok)->postJson('/api/team/invitations', ['email' => 'new@example.com'])->assertStatus(409)->assertJsonPath('reason', 'pending_invite');
        $list = $this->as($tok)->getJson('/api/team/invitations')->assertOk()->assertJsonPath('data.0.status', 'pending')->assertJsonPath('has_more', false);
        $this->flushHeaders();
        $this->postJson('/api/team/invitations/accept', ['token' => $inv->json('token'), 'password' => 'password123'])->assertOk()->assertJson(['next' => '/setup']);
        $admin = $this->postJson('/api/auth/login', ['email' => 'new@example.com', 'password' => 'password123'])->assertOk()->json('accessToken');

        $members = $this->as($tok)->getJson('/api/team/members')->assertOk()->json('data');
        $this->assertCount(2, $members);
        $this->assertArrayHasKey('performance', $members[1]);
        $id = $members[1]['id'];

        // admin defaults: can invite, cannot edit workspace details
        $this->as($admin)->patchJson('/api/workspace', ['name' => 'X'])->assertStatus(403)
            ->assertJson(['reason' => 'permission_denied', 'data' => ['permission' => 'workspace.manage'],
                'message' => 'You don\'t have permission to do this. Ask the workspace owner to turn on "Edit workspace details" for you.']);
        $this->as($tok)->patchJson("/api/team/members/{$id}/permissions", ['permissions' => ['workspace.manage' => true, 'team.invite' => false]])->assertOk()
            ->assertJson(['permissionOverrides' => ['workspace.manage' => true, 'team.invite' => false]]);
        $this->as($admin)->patchJson('/api/workspace', ['name' => 'Renamed'])->assertOk();
        $this->as($admin)->getJson('/api/team/invitations')->assertStatus(403);
        $this->as($tok)->patchJson("/api/team/members/{$id}/permissions", ['permissions' => ['team.invite' => null, 'nope' => true]])->assertStatus(400)->assertJsonPath('message', 'Unknown permission: nope');
        $this->as($tok)->patchJson("/api/team/members/{$id}/permissions", ['permissions' => ['team.invite' => null]])->assertOk()->assertJsonPath('permissionOverrides', ['workspace.manage' => true]);

        // role change and removal are owner-only
        $this->as($admin)->patchJson("/api/team/members/{$id}/role", ['role' => 'member'])->assertStatus(403);
        $this->as($tok)->patchJson("/api/team/members/{$id}/role", ['role' => 'member'])->assertOk()->assertJson(['ok' => true, 'role' => 'member']);
        $this->as($tok)->getJson("/api/team/members/{$id}")->assertOk()->assertJsonPath('role', 'member')->assertJsonPath('recentActivity.0.description', 'Renamed' === '' ? '' : 'new joined the workspace');
        $this->as($tok)->deleteJson("/api/team/members/{$id}")->assertOk();
        $this->as($admin)->getJson('/api/workspace')->assertStatus(401);
        $ownerId = $members[0]['id'];
        $this->as($tok)->deleteJson("/api/team/members/{$ownerId}")->assertStatus(403)->assertJsonPath('message', 'The owner cannot be removed.');
        $this->as($tok)->getJson('/api/team/members/not-a-uuid')->assertStatus(404);
    }

    public function test_member_plan_limit(): void
    {
        [$tok] = $this->owner('s@example.com', 'starter');
        $this->as($tok)->postJson('/api/team/invitations', ['email' => 'a@example.com'])->assertOk();
        $this->as($tok)->postJson('/api/team/invitations', ['email' => 'b@example.com'])->assertOk();
        $this->as($tok)->postJson('/api/team/invitations', ['email' => 'c@example.com'])->assertStatus(403)
            ->assertJson(['reason' => 'plan_limit', 'required_plan' => 'growth', 'message' => 'Your plan includes 3 team members. Upgrade to invite more.']);
    }

    public function test_notifications_logs_and_search(): void
    {
        [$tok] = $this->owner();
        $tid = $this->tenantId();
        for ($i = 0; $i < 3; $i++) {
            DB::table('notifications')->insert(['tenant_id' => $tid, 'kind' => 'info', 'description' => "N{$i}", 'created_at' => now()->subMinutes(10 - $i)]);
        }
        $p1 = $this->as($tok)->getJson('/api/notifications?limit=2')->assertOk()->assertJson(['unreadCount' => 3, 'has_more' => true]);
        $this->assertSame(['N2', 'N1'], array_column($p1->json('data'), 'description'));
        $p2 = $this->as($tok)->getJson('/api/notifications?limit=2&cursor='.$p1->json('next_cursor'))->assertJson(['has_more' => false, 'next_cursor' => null]);
        $this->assertSame(['N0'], array_column($p2->json('data'), 'description'));
        $this->as($tok)->getJson('/api/notifications?limit=101')->assertStatus(400);
        $this->as($tok)->postJson('/api/notifications/all/read')->assertOk();
        $this->as($tok)->getJson('/api/workspace')->assertJsonPath('notifications.unreadCount', 0);

        $this->as($tok)->getJson('/api/logs')->assertOk()->assertJsonPath('data.0.description', 'Workspace created by Olu Owner')->assertJsonPath('data.0.actorName', 'Camplo');

        DB::table('campaigns')->insert(['tenant_id' => $tid, 'name' => 'Spring 100% Sale', 'owner_id' => DB::table('users')->value('id'), 'start_date' => '2026-01-01']);
        $this->as($tok)->getJson('/api/search?q=100%')->assertOk()->assertJsonPath('campaigns.0.name', 'Spring 100% Sale');
        $this->as($tok)->getJson('/api/search?q=x')->assertExactJson(['campaigns' => [], 'leads' => [], 'pages' => []]);
    }

    public function test_logo_and_avatar_upload(): void
    {
        [$tok] = $this->owner();
        $png = UploadedFile::fake()->createWithContent('logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $url = $this->as($tok)->post('/api/workspace/logo', ['file' => $png], ['Accept' => 'application/json'])->assertOk()->json('logoUrl');
        $this->assertStringStartsWith('/api/files/logos/', $url);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
        $txt = UploadedFile::fake()->createWithContent('x.txt', 'hello');
        $this->as($tok)->post('/api/workspace/logo', ['file' => $txt], ['Accept' => 'application/json'])->assertStatus(400)->assertJsonPath('message', 'Logo must be a PNG or JPG.');
        $a = $this->as($tok)->post('/api/auth/me/avatar', ['file' => $png], ['Accept' => 'application/json'])->assertOk()->json('avatarUrl');
        $this->get($a)->assertOk();
        $this->as($tok)->deleteJson('/api/auth/me/avatar')->assertOk()->assertJsonPath('avatarUrl', null);
        $this->get($a)->assertStatus(404);
    }

    public function test_permission_catalog(): void
    {
        [$tok] = $this->owner();
        $c = $this->as($tok)->getJson('/api/team/permissions')->assertOk()->json();
        $this->assertCount(14, $c);
        $this->assertSame(['key' => 'campaigns.manage', 'label' => 'Create and edit campaigns', 'hint' => 'New campaigns, brief, budget and CPL threshold, pause, mark complete', 'admin' => true, 'member' => false], $c[0]);
    }
}
