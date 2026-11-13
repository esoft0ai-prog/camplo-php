<?php

namespace Tests\Feature;

use App\Services\Mailer;
use App\Support\Crypto;
use App\Support\Sql;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CampaignLeadTest extends TestCase
{
    /** A page row with a webhook secret, so leads can be ingested through the signed endpoint. */
    private function page(string $tenantId, ?string $campaignId, string $secret = 'page-secret-123', bool $vip = false): string
    {
        return Sql::insertId('deployments', [
            'tenant_id' => $tenantId, 'campaign_id' => $campaignId, 'name' => 'landing', 'subdomain' => 'landing-'.bin2hex(random_bytes(3)), 'storage_path' => 'x/',
            'status' => 'ready', 'webhook_secret_encrypted' => Crypto::encrypt($secret, 'webhook'), 'deployed_at' => now(), 'vip' => $vip,
        ]);
    }

    private function ingest(string $tid, string $pid, array $body, string $secret = 'page-secret-123')
    {
        $raw = json_encode($body);

        return $this->call('POST', "/api/v1/ingest/{$tid}/{$pid}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CAMPLO_SIGNATURE' => 'sha256='.hash_hmac('sha256', $raw, $secret)], $raw);
    }

    public function test_campaign_crud_pin_and_plan_limit(): void
    {
        [$tok] = $this->owner('s@example.com', 'starter');
        $c = $this->as($tok)->postJson('/api/campaigns', ['name' => '  Spring ', 'budget' => 1000, 'cplThreshold' => 12, 'dailySpend' => 50])->assertOk()
            ->assertJson(['name' => 'Spring', 'status' => 'active', 'budget' => 1000, 'cplThreshold' => 12, 'leadCount' => 0, 'health' => null, 'cpl' => null, 'daysActive' => 0])->json();
        $this->assertCount(1, $c['members']);
        $this->as($tok)->postJson('/api/campaigns', ['name' => 'B'])->assertOk();
        $this->as($tok)->postJson('/api/campaigns', ['name' => 'C'])->assertOk();
        $this->as($tok)->postJson('/api/campaigns', ['name' => 'D'])->assertStatus(403)->assertJson(['reason' => 'plan_limit', 'required_plan' => 'growth']);
        $this->as($tok)->postJson('/api/campaigns', ['name' => ''])->assertStatus(400);

        $this->as($tok)->patchJson("/api/campaigns/{$c['id']}", ['status' => 'paused', 'dailySpend' => 75, 'change' => ['type' => 'creative', 'description' => 'New hero video']])->assertOk()
            ->assertJson(['status' => 'paused', 'dailySpend' => 75]);
        $this->assertSame(1, DB::table('campaign_changes')->count());
        $this->as($tok)->getJson("/api/campaigns/{$c['id']}/memory")->assertOk()->assertJsonPath('data.0.type', 'creative')->assertJsonPath('data.0.before.lead_count_7d', 0);
        $logs = $this->as($tok)->getJson("/api/campaigns/{$c['id']}/logs")->assertStatus(403)->assertJson(['reason' => 'plan_limit']); // campaign_logs is Growth+

        $this->as($tok)->postJson("/api/campaigns/{$c['id']}/pin")->assertOk()->assertJson(['pinned' => true]);
        $this->as($tok)->getJson('/api/campaigns')->assertOk()->assertJsonPath('data.0.id', $c['id'])->assertJsonPath('has_more', false);
        $this->as($tok)->getJson('/api/campaigns/'.Str::uuid())->assertStatus(404)->assertJsonPath('message', 'Campaign not found.');
        $this->as($tok)->deleteJson("/api/campaigns/{$c['id']}")->assertOk();
        $this->assertSame(2, DB::table('campaigns')->count());
    }

    public function test_campaign_logs_paginate_with_cursor(): void
    {
        [$tok] = $this->owner();
        $id = $this->as($tok)->postJson('/api/campaigns', ['name' => 'Logs'])->json('id');
        for ($i = 0; $i < 5; $i++) {
            DB::table('campaign_logs')->insert(['tenant_id' => $this->tenantId(), 'campaign_id' => $id, 'description' => "L{$i}", 'created_at' => now()->subMinutes(10 - $i)]);
        }
        $p1 = $this->as($tok)->getJson("/api/campaigns/{$id}/logs?limit=3")->assertOk()->assertJson(['has_more' => true]);
        $this->assertSame(['Campaign created by Olu Owner', 'L4', 'L3'], array_column($p1->json('data'), 'description'));
        $p2 = $this->as($tok)->getJson("/api/campaigns/{$id}/logs?limit=3&cursor=".$p1->json('next_cursor'))->assertOk()->assertJson(['has_more' => false]);
        $this->assertSame(['L2', 'L1', 'L0'], array_column($p2->json('data'), 'description'));
    }

    public function test_ingest_respond_audit_and_win_signal(): void
    {
        [$tok] = $this->owner();
        $tid = $this->tenantId();
        $cid = $this->as($tok)->postJson('/api/campaigns', ['name' => 'Ads'])->json('id');
        $pid = $this->page($tid, $cid);
        $this->ingest($tid, $pid, ['full_name' => 'Ada Lead'], 'wrong-secret')->assertStatus(401)->assertJson(['ok' => false]);
        $r = $this->ingest($tid, $pid, ['data' => ['first_name' => 'Ada', 'last_name' => 'Lead', 'email' => 'ada@lead.test', 'utm_source' => 'facebook']])->assertStatus(201)->assertJson(['ok' => true]);
        $leadId = $r->json('lead_id');

        $list = $this->as($tok)->getJson('/api/leads')->assertOk()->assertJson(['total' => 1, 'not_responded' => 1, 'overdue' => 0, 'has_more' => false]);
        $l = $list->json('data.0');
        $this->assertSame(['Ada Lead', 'ada@lead.test', 'not_responded', true, 'facebook', 240], [$l['name'], $l['email'], $l['status'], $l['canRespond'], $l['utm']['source'], $l['thresholdMinutes']]);
        $this->assertMatchesRegularExpression('/^CP[0-9A-F]{6}$/', $l['displayId']);
        $this->as($tok)->getJson("/api/campaigns/{$cid}")->assertJson(['leadCount' => 1, 'notRespondedCount' => 1, 'health' => 'critical', 'healthSignals' => ['acknowledgment' => 'red', 'webhook' => 'green']]);
        $this->assertSame('operational', DB::table('webhook_sources')->value('status'));

        $this->as($tok)->postJson("/api/leads/{$leadId}/respond")->assertOk()->assertJson(['status' => 'responded', 'assignmentPath' => 'A', 'respondedByName' => 'Olu Owner', 'canRespond' => false]);
        $this->as($tok)->postJson("/api/leads/{$leadId}/respond")->assertStatus(409)->assertJson(['reason' => 'already_responded']);
        $audit = $this->as($tok)->getJson("/api/leads/{$leadId}/audit")->assertOk()->assertJson(['status' => 'responded']);
        $this->assertSame(['Lead received', 'Available to all team members', 'Responded — Olu Owner (claimed and acknowledged)'], array_column($audit->json('data'), 'description'));
        $this->assertSame(['Lead received', 'Responded — Olu Owner'], array_column($this->as($tok)->getJson("/api/leads/{$leadId}/lifecycle")->json('data'), 'event'));
        // Responded inside 5 minutes on Growth → win card
        $this->as($tok)->getJson('/api/insights')->assertOk()->assertJsonPath('data.0.type', 'win')->assertJsonPath('data.0.campaign_tag', 'Ads');
        $this->as($tok)->getJson("/api/campaigns/{$cid}/leads?status=responded")->assertOk()->assertJsonPath('total', 1)->assertJsonCount(1, 'data');
    }

    public function test_named_inbound_hook_token_and_tally_signature(): void
    {
        $this->owner();
        $tid = $this->tenantId();
        $hook = Sql::insertId('inbound_webhooks', ['tenant_id' => $tid, 'source_label' => 'Zapier', 'url' => 'x', 'secret_encrypted' => Crypto::encrypt('hook-secret', 'webhook')]);
        $this->postJson("/api/v1/hooks/{$hook}?token=nope", ['name' => 'X'])->assertStatus(401);
        $this->postJson("/api/v1/hooks/{$hook}?token=hook-secret", ['name' => 'Zap Lead', 'email' => 'z@x.test'])->assertStatus(201);
        $raw = json_encode(['data' => ['fields' => [['label' => 'Full name', 'value' => 'Tally Person'], ['label' => 'Email', 'value' => 't@x.test']]]]);
        $this->call('POST', "/api/v1/hooks/{$hook}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_TALLY_SIGNATURE' => base64_encode(hash_hmac('sha256', $raw, 'hook-secret', true))], $raw)->assertStatus(201);
        $this->assertEqualsCanonicalizing(['Zap Lead', 'Tally Person'], DB::table('leads')->pluck('full_name')->all());
        $this->assertSame('Zapier', DB::table('leads')->where('full_name', 'Zap Lead')->value('source_system'));
    }

    public function test_assignment_paths_and_member_visibility(): void
    {
        [$tok] = $this->owner();
        $tid = $this->tenantId();
        $pid = $this->page($tid, null);
        $member = $this->teammate($tok, 'm@example.com');
        $admin = $this->teammate($tok, 'a@example.com', 'admin');
        $mid = DB::table('users')->where('email', 'm@example.com')->value('id');
        $aid = DB::table('users')->where('email', 'a@example.com')->value('id');
        $one = $this->ingest($tid, $pid, ['name' => 'One'])->json('lead_id');
        $two = $this->ingest($tid, $pid, ['name' => 'Two'])->json('lead_id');

        $this->as($admin)->postJson("/api/leads/{$one}/assign", ['assigneeId' => $mid])->assertStatus(403)->assertJson(['reason' => 'owner_only']);
        $this->as($tok)->postJson("/api/leads/{$one}/assign", ['assigneeId' => $aid])->assertOk()->assertJson(['assignmentPath' => 'B', 'assigneeName' => 'Tee Mate']);
        $this->as($tok)->postJson("/api/leads/{$one}/assign", ['assigneeId' => $mid])->assertOk()->assertJson(['assignmentPath' => 'C', 'assigneeId' => $mid]);
        $this->as($tok)->postJson("/api/leads/{$two}/assign", ['assigneeId' => $aid])->assertOk();
        // the member sees their own lead and unassigned ones only
        $this->as($member)->getJson('/api/leads')->assertOk()->assertJson(['total' => 1])->assertJsonPath('data.0.id', $one);
        $this->as($member)->getJson("/api/leads/{$two}")->assertStatus(404);
        $this->as($admin)->postJson("/api/leads/{$one}/respond")->assertStatus(403)->assertJsonPath('message', 'This lead is assigned to someone else.');
        $audit = $this->as($tok)->getJson("/api/leads/{$one}/audit")->json('data');
        $this->assertStringStartsWith('Reassigned from Tee Mate to Tee Mate by Olu Owner', $audit[2]['description']);
        $this->assertSame(1, DB::table('notifications')->where('user_id', $mid)->where('kind', 'assignment')->count());
    }

    public function test_sla_sweep_alert_and_acknowledge_link(): void
    {
        [$tok] = $this->owner();
        $tid = $this->tenantId();
        $pid = $this->page($tid, null);
        $leadId = $this->ingest($tid, $pid, ['name' => 'Late Lead'])->json('lead_id');
        DB::table('leads')->where('id', $leadId)->update(['received_at' => now()->subMinutes(300)]);
        $this->as($tok)->getJson('/api/sla/live')->assertOk()->assertJson(['overdueCount' => 1])->assertJsonPath('brief.mostOverdue.name', 'Late Lead');
        $this->as($tok)->getJson('/api/sla/overdue')->assertOk()->assertJson(['count' => 1])->assertJsonPath('data.0.name', 'Late Lead');

        $this->artisan('camplo:sweep')->assertSuccessful();
        $mail = collect(Mailer::$outbox)->first(fn ($m) => str_starts_with($m['subject'], 'Urgent: Late Lead'));
        $this->assertNotNull($mail);
        $this->assertNotNull(DB::table('leads')->where('id', $leadId)->value('sla_alert_sent_at'));
        $this->artisan('camplo:sweep')->assertSuccessful(); // idempotent
        $this->assertSame(1, collect(Mailer::$outbox)->filter(fn ($m) => str_starts_with($m['subject'], 'Urgent: Late Lead'))->count());

        preg_match('#/api/acknowledge/([A-Za-z0-9_-]+)#', $mail['text'], $m);
        $this->get("/api/acknowledge/{$m[1]}")->assertRedirect('/#/acknowledge?token='.$m[1]);
        $this->flushHeaders();
        $this->getJson('/api/leads/acknowledge/preview?token='.$m[1])->assertOk()->assertJson(['state' => 'ok', 'lead' => ['name' => 'Late Lead']]);
        $this->postJson('/api/leads/acknowledge', ['token' => $m[1]])->assertOk()->assertJson(['state' => 'done']);
        $this->postJson('/api/leads/acknowledge', ['token' => $m[1]])->assertOk()->assertJson(['state' => 'already']);
        $this->as($tok)->getJson('/api/sla/live')->assertJson(['overdueCount' => 0]);
        $this->as($tok)->getJson('/api/sla/team')->assertOk()->assertJsonPath('data.0.name', 'Olu Owner');
        $this->as($tok)->getJson('/api/sla/trend')->assertOk()->assertJsonCount(7, 'days');
    }

    public function test_sla_config_and_manual_notify(): void
    {
        [$tok] = $this->owner();
        $this->as($tok)->patchJson('/api/sla/config', ['sla_threshold_minutes' => 20, 'vip_sla_threshold_minutes' => 25])->assertOk()
            ->assertJson(['ok' => true, 'warnings' => ['VIP threshold is usually lower than the standard threshold']]);
        $this->as($tok)->getJson('/api/sla/config')->assertJson(['sla_threshold_minutes' => 20]);
        $this->as($tok)->patchJson('/api/sla/config', ['sla_threshold_minutes' => 0])->assertStatus(400);
        $pid = $this->page($this->tenantId(), null);
        $leadId = $this->ingest($this->tenantId(), $pid, ['name' => 'N'])->json('lead_id');
        $this->as($tok)->postJson("/api/sla/notify/{$leadId}")->assertOk()->assertJson(['ok' => true, 'channels' => ['email']]);
    }

    public function test_notes_edit_window_and_team_notes(): void
    {
        [$tok] = $this->owner();
        $member = $this->teammate($tok, 'm@example.com');
        $mid = DB::table('users')->where('email', 'm@example.com')->value('id');
        $cid = $this->as($tok)->postJson('/api/campaigns', ['name' => 'Notes'])->json('id');
        $n = $this->as($tok)->postJson("/api/campaigns/{$cid}/notes", ['content' => ' First '])->assertOk()->assertJson(['content' => 'First', 'editable' => true])->json();
        $this->as($tok)->patchJson("/api/campaigns/{$cid}/notes/{$n['id']}", ['content' => 'Edited'])->assertOk()->assertJson(['content' => 'Edited']);
        $this->as($member)->patchJson("/api/notes/{$n['id']}", ['content' => 'Hijack'])->assertStatus(403);
        DB::table('notes')->where('id', $n['id'])->update(['created_at' => now()->subHours(3)]);
        $this->as($tok)->patchJson("/api/notes/{$n['id']}", ['content' => 'Late'])->assertStatus(403)->assertJson(['reason' => 'edit_window_closed']);
        $this->as($tok)->postJson("/api/campaigns/{$cid}/meeting-notes", ['title' => 'Sync', 'content' => 'Agenda'])->assertOk()->assertJson(['type' => 'meeting', 'via' => 'Manual']);

        $tn = $this->as($tok)->postJson('/api/team-notes', ['content' => 'Call them', 'recipientIds' => [$mid], 'attachmentType' => 'campaign', 'attachmentId' => $cid, 'deadline' => now()->addMinutes(30)->toIso8601String()])
            ->assertOk()->assertJson(['deadlineStatus' => 'pending', 'approaching' => true, 'attachment' => ['type' => 'campaign', 'name' => 'Notes']])->json();
        $this->as($member)->getJson('/api/team-notes?addressedTo=me')->assertOk()->assertJsonPath('data.0.unread', true)->assertJsonPath('data.0.canAcknowledge', true);
        $this->as($member)->postJson('/api/team-notes', ['content' => 'x', 'recipientIds' => [$mid]])->assertStatus(403)->assertJson(['reason' => 'permission_denied']);
        $this->as($tok)->postJson("/api/team-notes/{$tn['id']}/read")->assertStatus(403);
        $this->as($member)->postJson("/api/team-notes/{$tn['id']}/acknowledge")->assertOk();
        $this->as($member)->getJson('/api/team-notes')->assertJsonPath('data.0.deadlineStatus', 'met')->assertJsonPath('data.0.unread', false);
        $this->assertSame(1, DB::table('notifications')->where('user_id', $mid)->where('kind', 'team_note')->count());
    }

    public function test_share_link_public_view_and_retrospective(): void
    {
        [$tok] = $this->owner('w@example.com', 'watchtower');
        $tid = $this->tenantId('w@example.com');
        $cid = $this->as($tok)->postJson('/api/campaigns', ['name' => 'Finished'])->json('id');
        DB::table('users')->update(['name' => 'Olu Owner']);
        DB::table('insights')->insert(['tenant_id' => $tid, 'campaign_id' => $cid, 'type' => 'observation', 'severity' => 'blue', 'observation' => "Olu's team answered fast.", 'evidence' => 'x']);
        $url = $this->as($tok)->postJson("/api/campaigns/{$cid}/share-link")->assertOk()->json('shareUrl');
        $token = substr($url, strrpos($url, '/') + 1);
        $this->flushHeaders();
        $this->getJson("/api/public/campaigns/{$token}")->assertOk()->assertJson(['campaign_name' => 'Finished', 'powered_by' => 'Camplo'])
            ->assertJsonPath('insights.0.observation', 'The team team answered fast.');
        $this->as($tok)->deleteJson("/api/campaigns/{$cid}/share-link")->assertOk();
        $this->flushHeaders();
        $this->getJson("/api/public/campaigns/{$token}")->assertStatus(404);

        $this->as($tok)->getJson("/api/campaigns/{$cid}/retrospective")->assertStatus(404);
        $this->as($tok)->postJson("/api/campaigns/{$cid}/complete")->assertOk();
        $this->as($tok)->postJson("/api/campaigns/{$cid}/complete")->assertStatus(409);
        $this->as($tok)->getJson("/api/campaigns/{$cid}/retrospective/status")->assertJson(['generated' => true]);
        $this->as($tok)->getJson("/api/campaigns/{$cid}/retrospective")->assertOk()->assertJson(['generated' => true, 'campaignName' => 'Finished', 'totalLeads' => 0]);
        $pdf = $this->as($tok)->get("/api/campaigns/{$cid}/retrospective/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertStringContainsString('finished-retrospective.pdf', $pdf->headers->get('Content-Disposition'));
    }

    public function test_live_updates_endpoint(): void
    {
        [$tok] = $this->owner();
        $tid = $this->tenantId();
        $first = $this->as($tok)->get('/api/rt/workspace/leads')->assertOk()->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');
        preg_match('/^id: (\d+)$/m', $first->getContent(), $m);
        $this->assertStringNotContainsString('event: update', $first->getContent());
        $pid = $this->page($tid, null);
        $this->ingest($tid, $pid, ['name' => 'Live']);
        $next = $this->as($tok)->withHeader('Last-Event-ID', $m[1])->get('/api/rt/workspace/leads')->assertOk();
        $this->assertStringContainsString('event: update', $next->getContent());
        $this->assertStringContainsString('"type":"created"', $next->getContent());
        $this->flushHeaders();
        $this->get('/api/rt/workspace/leads')->assertStatus(401);
    }

    public function test_cron_requires_secret(): void
    {
        $this->getJson('/api/cron')->assertStatus(401);
        config(['camplo.cron_secret' => 'cron-secret-value']);
        $this->withHeader('Authorization', 'Bearer cron-secret-value')->getJson('/api/cron')->assertOk()->assertJson(['ok' => true]);
    }

    public function test_demo_seed_powers_every_dashboard_call(): void
    {
        $this->artisan('camplo:seed-demo', ['--force' => true])->assertSuccessful();
        $tok = $this->postJson('/api/auth/login', ['email' => 'marcus@northbeam.demo', 'password' => 'camplo-demo'])->assertOk()->assertJson(['next' => '/dashboard'])->json('accessToken');
        foreach (['/auth/me', '/workspace', '/campaigns?limit=100', '/leads?limit=100', '/insights?limit=100', '/recommendations?limit=100', '/pages?limit=100',
            '/team/members?limit=100', '/sla/live', '/notifications', '/team-notes?limit=100', '/sla/cross-tool', '/sla/config/cross-tool', '/chat/history?limit=100', '/chat/suggestions'] as $path) {
            $this->as($tok)->getJson("/api{$path}")->assertOk();
        }
        $this->as($tok)->getJson('/api/insights')->assertJsonPath('data.0.type', 'priority_flag');
        $this->as($tok)->getJson('/api/recommendations')->assertJsonCount(3, 'data')->assertJsonPath('data.0.memory_timeline.0.kind', 'experiment');
        $pages = $this->as($tok)->getJson('/api/pages')->json();
        $this->assertCount(8, $pages['data']);
        $this->assertArrayHasKey('secret', $pages['data'][0]['webhook']);
        $summer = DB::table('campaigns')->where('name', 'Summer Launch 2026')->value('id');
        $this->as($tok)->getJson("/api/campaigns/{$summer}/retrospective")->assertOk()->assertJson(['generated' => true, 'totalLeads' => 120]);
        $this->as($tok)->getJson('/api/leads?status=overdue')->assertOk()->assertJsonPath('overdue', fn ($n) => $n > 0);
        $bf = DB::table('campaigns')->where('name', 'Black Friday 2026')->value('id');
        $this->as($tok)->getJson("/api/campaigns/{$bf}/lifecycle/summary")->assertOk()->assertJsonPath('total_leads', fn ($n) => $n > 100);
        $this->artisan('camplo:seed-demo')->expectsOutputToContain('already has workspaces')->assertSuccessful();
    }
}
