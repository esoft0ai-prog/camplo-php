<?php

namespace App\Services;

use App\Support\Crypto;
use Illuminate\Support\Facades\DB;

/**
 * Demo workspace ("Northbeam Growth") so a fresh database is explorable — the same data as the Node seed.
 * Timestamps are relative to seed time so SLA timers are live.
 */
final class DemoSeeder
{
    public const EMAIL = 'marcus@northbeam.demo';

    public static function password(): string
    {
        return (string) (env('DEMO_PASSWORD') ?: 'camplo-demo');
    }

    private static int $now;

    private const MIN = 60;

    private const HOUR = 3600;

    private const DAY = 86400;

    /** A timestamp `$s` seconds ago (fractional seconds allowed). */
    private static function ago(float $s): string
    {
        return self::at(self::$now - $s);
    }

    private static function at(float $t): string
    {
        $ms = (int) round($t * 1000);

        return gmdate('Y-m-d H:i:s', intdiv($ms, 1000)).'.'.str_pad((string) ($ms % 1000), 3, '0', STR_PAD_LEFT).'+00';
    }

    private static function day(float $d): string
    {
        return gmdate('Y-m-d', (int) (self::$now - $d * self::DAY));
    }

    private static function id(string $table, array $row): string
    {
        return DB::table($table)->insertGetId($row);
    }

    public static function run(): string
    {
        self::$now = time();
        $pw = AuthService::hashPassword(self::password());
        $T = self::id('tenants', [
            'business_name' => 'Northbeam Growth', 'owner_name' => 'Marcus Adeyemi', 'owner_email' => self::EMAIL, 'notification_email' => self::EMAIL,
            'status' => 'active', 'setup_complete' => true, 'stack_check_completed' => true, 'has_marketing_stack' => true, 'plan' => 'watchtower',
            'sla_threshold_minutes' => 30, 'vip_sla_threshold_minutes' => 10, 'vip_lead_enabled' => true, 'activated_at' => self::ago(90 * self::DAY), 'team_size' => '3–10',
            'storage_quota_bytes' => config('camplo.default_storage_quota_bytes'),
        ]);
        $people = [['Marcus Adeyemi', self::EMAIL, 'owner'], ['Tunde Omolayo', 'tunde@northbeam.demo', 'admin'], ['Sarah Okafor', 'sarah@northbeam.demo', 'member'],
            ['Amara Nwosu', 'amara@northbeam.demo', 'member'], ['Kofi Mensah', 'kofi@northbeam.demo', 'member']];
        $active = [2, 6, 24, 180, 1440];
        $U = [];
        foreach ($people as $i => [$name, $email, $role]) {
            $U[] = (object) ['id' => self::id('users', ['tenant_id' => $T, 'name' => $name, 'email' => $email, 'role' => $role, 'password_hash' => $pw,
                'joined_at' => self::ago(80 * self::DAY), 'last_active_at' => self::ago($active[$i] * self::MIN)]), 'name' => $name];
        }
        [$marcus, $tunde, $sarah, $amara, $kofi] = $U;
        DB::table('users')->insert(['tenant_id' => $T, 'name' => 'ops', 'email' => 'ops@northbeam.demo', 'role' => 'member', 'invited_by' => $marcus->id,
            'invitation_token' => 'seeded', 'invitation_expires_at' => self::at(self::$now + 5 * self::DAY), 'created_at' => self::ago(2 * self::DAY)]);
        DB::table('ai_provider_configs')->insert(['tenant_id' => $T]);

        $camp = fn (array $c) => self::id('campaigns', ['tenant_id' => $T, 'owner_id' => $marcus->id, 'currency' => 'USD', ...$c]);
        $bf = $camp(['name' => 'Black Friday 2026', 'start_date' => self::day(25), 'pinned' => true, 'budget' => 9000, 'daily_spend' => 1850, 'cpl_threshold' => 12,
            'description' => '<p>Paid social push for the Black Friday bundle. Three landing pages on Meta traffic with Tally forms feeding Twenty CRM.</p><p>Goal: keep speed-to-lead under 5 minutes and CPL inside threshold.</p>']);
        $prop = $camp(['name' => 'Lekki Property Enquiry — Q3', 'start_date' => self::day(45), 'pinned' => true, 'budget' => 4800, 'daily_spend' => 980, 'cpl_threshold' => 25,
            'description' => '<p>Enquiry funnel for off-plan units. Leads route from GoHighLevel. High ticket — every lead is worth a call inside 5 minutes.</p>']);
        $web = $camp(['name' => 'Growth Webinar Series', 'start_date' => self::day(68), 'pinned' => true,
            'description' => '<p>Weekly webinar registrations from Systeme.io. Nurture sequence in Brevo, sales follow-up for attendees only.</p>']);
        $ref = $camp(['name' => 'Customer Referral Drive', 'start_date' => self::day(9), 'budget' => 1200, 'daily_spend' => 110, 'cpl_threshold' => 15,
            'description' => '<p>Referral page shared with existing customers. Low volume, high intent. Typeform capture.</p>']);
        $sum = $camp(['name' => 'Summer Launch 2026', 'start_date' => self::day(117), 'status' => 'complete', 'completed_at' => self::ago(26 * self::DAY), 'budget' => 32000, 'daily_spend' => 3200,
            'description' => '<p>Product launch across Meta and Google. Completed; retrospective generated automatically.</p>']);
        $names = [$bf => 'Black Friday 2026', $prop => 'Lekki Property Enquiry — Q3'];
        foreach ([[$bf, [$marcus, $tunde, $sarah, $kofi]], [$prop, [$marcus, $tunde, $amara]], [$web, [$marcus, $amara, $kofi]], [$ref, [$marcus, $sarah]], [$sum, $U]] as [$c, $us]) {
            DB::table('campaign_members')->insert(array_map(fn ($u) => ['tenant_id' => $T, 'campaign_id' => $c, 'user_id' => $u->id], $us));
        }

        $html = fn (string $title) => '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$title.'</title>
<style>body{margin:0;font-family:system-ui,sans-serif;background:#0B0B0F;color:#E8E8F0;display:grid;place-items:center;min-height:100vh}main{max-width:520px;padding:32px}h1{font-size:34px;margin:0 0 12px}p{color:#8888A8}input,button{width:100%;padding:12px;margin-top:10px;border-radius:8px;border:1px solid #262636;background:#18181F;color:#E8E8F0;font:inherit}button{background:#FF8C00;color:#000;font-weight:600;border:0}</style></head>
<body><main><h1>'.$title.'</h1><p>Demo landing page hosted by Camplo. Submissions post to this page\'s signed Camplo webhook.</p><form onsubmit="event.preventDefault();this.innerHTML=\'<p>Thanks — we will be in touch within minutes.</p>\'"><input placeholder="Full name" required><input type="email" placeholder="Email" required><button>Get the offer</button></form></main></body></html>';
        $pageDefs = [
            ['bf-bundle-a', $bf, 25, 3, 1200, false], ['bf-bundle-b', $bf, 25, 130, 800, false], ['bf-vip-early', $bf, 1.3, 132, 250, false],
            ['lekki-offplan', $prop, 45, 11, 420, true], ['lekki-brochure', $prop, 40, null, 130, true], ['webinar-register', $web, 68, 1, 530, false],
            ['refer-a-friend', $ref, 9, 47, 60, false], ['summer-main', $sum, 117, 40 * 24 * 60, 0, false],
        ];
        $source = fn ($c) => $c === $prop ? 'gohighlevel' : ($c === $web ? 'systeme_io' : ($c === $ref ? 'typeform' : 'tally'));
        $P = [];
        foreach ($pageDefs as [$name, $c, $deployedDays, $lastMin, $visits, $vip]) {
            $d = self::id('deployments', [
                'tenant_id' => $T, 'campaign_id' => $c, 'name' => $name, 'subdomain' => $name.'-'.substr(bin2hex(random_bytes(3)), 0, 4), 'storage_path' => 'pending',
                'status' => 'ready', 'storage_size_bytes' => 1400, 'webhook_secret_encrypted' => Crypto::encrypt(Crypto::randomToken(24), 'webhook'), 'deployed_at' => self::ago($deployedDays * self::DAY), 'vip' => $vip,
                'serving_state' => $name === 'summer-main' ? 'archived' : ($name === 'lekki-brochure' ? 'paused' : 'active'),
                'early_warning_active' => $deployedDays < 3, 'previous_storage_path' => $name === 'bf-bundle-b' ? "sites/{$T}/prev/" : null, 'previous_deployed_at' => $name === 'bf-bundle-b' ? self::ago(3 * self::DAY) : null,
            ]);
            $prefix = "sites/{$T}/{$d}/v1/";
            DB::table('stored_files')->insert(['path' => "{$prefix}index.html", 'content_type' => 'text/html; charset=utf-8', 'data_base64' => base64_encode($html(str_replace('-', ' ', $name)))]);
            DB::table('deployments')->where('id', $d)->update(['storage_path' => $prefix]);
            DB::table('webhook_sources')->insert(['tenant_id' => $T, 'deployment_id' => $d, 'campaign_id' => $c, 'label' => $name, 'source_system' => $source($c),
                'last_received_at' => $lastMin === null ? null : self::ago($lastMin * self::MIN), 'status' => $lastMin !== null && $lastMin > 120 ? 'offline' : 'operational']);
            if ($visits) {
                $rows = [];
                for ($i = 0; $i < min(14, (int) ceil($deployedDays)); $i++) {
                    $rows[] = ['tenant_id' => $T, 'deployment_id' => $d, 'day' => self::day($i), 'visits' => (int) round($visits * (0.75 + (($i * 37) % 50) / 100))];
                }
                DB::table('page_visits')->insert($rows);
            }
            $P[$name] = (object) ['id' => $d, 'campaign_id' => $c, 'vip' => $vip];
        }

        // Leads: [name, page, minutes ago, state, responder, response seconds]
        $L = [
            ['Sarah Jenkins', 'bf-bundle-a', 3.4, 'unassigned', null], ['Chidi Eze', 'lekki-offplan', 8.2, 'assigned', $marcus], ['Folake Ade', 'bf-bundle-a', 41, 'assigned', $sarah],
            ['David Mensah', 'bf-bundle-a', 194, 'unassigned', null], ['Ngozi Obi', 'webinar-register', 12, 'responded', $amara, 142], ['Ibrahim Musa', 'lekki-offplan', 67, 'assigned', $tunde],
            ['Grace Lawal', 'bf-bundle-b', 22, 'responded', $tunde, 222], ['Emeka Nnaji', 'refer-a-friend', 95, 'responded', $sarah, 310], ['Aisha Bello', 'webinar-register', 140, 'responded', $kofi, 188],
            ['Yemi Alade', 'bf-bundle-b', 180, 'responded', $sarah, 2710], ['Kwame Asante', 'bf-bundle-a', 1.1, 'unassigned', null], ['Zainab Yusuf', 'webinar-register', 300, 'responded', $amara, 96],
            ['Tolu Bakare', 'lekki-offplan', 420, 'responded', $marcus, 204], ['Joy Ekpo', 'bf-bundle-a', 510, 'responded', $kofi, 1310], ['Segun Arinze', 'webinar-register', 600, 'responded', $amara, 120],
            ['Halima Sani', 'bf-bundle-a', 55, 'assigned', $kofi], ['Uche Okoro', 'refer-a-friend', 800, 'responded', $sarah, 480], ['Bisi Coker', 'webinar-register', 1000, 'responded', $kofi, 260],
            ['Femi Kuti', 'bf-bundle-a', 1400, 'responded', $tunde, 175], ['Ada Igwe', 'lekki-offplan', 1600, 'responded', $tunde, 330],
        ];
        $first = ['Tobi', 'Kemi', 'Musa', 'Ifeoma', 'Chinedu', 'Bola', 'Esther', 'Obinna', 'Lara', 'Sani', 'Nneka', 'Dayo', 'Hauwa', 'Kunle', 'Precious'];
        $last = ['Adebayo', 'Okeke', 'Balogun', 'Eze', 'Ibrahim', 'Nwachukwu', 'Ogunleye', 'Danjuma', 'Afolabi', 'Chukwu'];
        $bulkPages = ['bf-bundle-a', 'bf-bundle-a', 'bf-bundle-b', 'webinar-register', 'webinar-register', 'webinar-register', 'lekki-offplan', 'refer-a-friend'];
        $responders = [$tunde, $sarah, $amara, $kofi, $marcus];
        for ($i = 0; $i < 260; $i++) {
            $minutes = 60 * 26 + (($i * 97) % (60 * 24 * 20));
            $pg = $bulkPages[$i % count($bulkPages)];
            $slow = str_starts_with($pg, 'bf-bundle-b') || $i % 11 === 0;
            $L[] = [$first[$i % 15].' '.$last[($i * 7) % 10], $pg, $minutes, $i % 29 === 0 ? 'unassigned' : 'responded', $i % 29 === 0 ? null : $responders[$i % 5], $slow ? 900 + ($i % 5) * 600 : 60 + (($i * 53) % 280)];
        }
        for ($i = 0; $i < 120; $i++) {
            $L[] = [$first[($i + 3) % 15].' '.$last[($i + 2) % 10], 'summer-main', (27 + ($i % 80)) * 24 * 60, 'responded', $responders[$i % 5], 90 + (($i * 41) % 420)];
        }
        $byId = [];
        foreach ($U as $u) {
            $byId[$u->id] = $u->name;
        }
        $rows = [];
        foreach ($L as $i => $x) {
            [$fullName, $pg, $minAgo, $st, $who] = $x;
            $resp = $x[5] ?? null;
            $d = $P[$pg];
            $received = self::$now - $minAgo * self::MIN;
            $thr = $d->vip ? 10 : 30;
            $utm = $i % 4 === 3 ? ['utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null, 'utm_content' => null, 'utm_term' => null]
                : ['utm_source' => $i % 2 ? 'facebook' : 'instagram', 'utm_medium' => 'paid_social', 'utm_campaign' => explode('-', $pg)[0].'_2026', 'utm_content' => $i % 3 ? 'carousel_v2' : 'video_15s', 'utm_term' => $i % 5 === 0 ? 'bundle deal' : null];
            $row = [
                'tenant_id' => $T, 'deployment_id' => $d->id, 'campaign_id' => $d->campaign_id, 'full_name' => $fullName, 'email' => preg_replace('/[^a-z]+/', '.', strtolower($fullName)).$i.'@example.com',
                'phone' => '+234 80'.str_pad((string) (31 + ($i % 60)), 2, '0', STR_PAD_LEFT).' '.((412 + $i * 7) % 1000).' '.(1000 + (($i * 37) % 9000)),
                'source_system' => $source($d->campaign_id), 'source_identifier' => 'sub_'.base_convert((string) (98231 + $i * 13), 10, 36), 'vip' => $d->vip,
                'received_at' => self::at($received), 'created_at' => self::at($received), 'has_external_lifecycle_events' => $i % 3 !== 1, ...$utm,
                'sla_alert_sent_at' => $minAgo > $thr ? self::at($received + $thr * self::MIN) : null,
                'status' => 'not_responded', 'assignment_path' => null, 'assignee_id' => null, 'claimed_at' => null, 'responded_at' => null, 'responded_by' => null, 'assigned_at' => null,
            ];
            if ($st === 'responded') {
                $r = self::at($received + $resp);
                $row = [...$row, 'status' => 'responded', 'assignment_path' => 'A', 'assignee_id' => $who->id, 'claimed_at' => $r, 'responded_at' => $r, 'responded_by' => $who->id,
                    'sla_alert_sent_at' => $resp > 1800 ? self::at($received + 1800) : null];
            } elseif ($st === 'assigned') {
                $row = [...$row, 'assignment_path' => 'B', 'assignee_id' => $who->id, 'assigned_at' => self::at($received + min(4 * self::MIN, $minAgo * self::MIN * 0.3))];
            }
            $rows[] = $row;
        }
        $inserted = [];
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('leads')->insert($chunk);
        }
        $inserted = DB::table('leads')->where('tenant_id', $T)->orderBy('created_at')->get()->all();
        // keep the seed order (created_at ties are possible) — match by email, which carries the index
        $order = array_flip(array_column($rows, 'email'));
        usort($inserted, fn ($a, $b) => $order[$a->email] <=> $order[$b->email]);

        $hist = $life = [];
        foreach ($inserted as $h => $l) {
            $life[] = ['tenant_id' => $T, 'lead_id' => $l->id, 'event' => 'Lead received', 'source' => 'camplo', 'event_timestamp' => $l->received_at, 'is_milestone' => false];
            if ($l->assignment_path === 'B') {
                $hist[] = ['tenant_id' => $T, 'lead_id' => $l->id, 'to_assignee_id' => $l->assignee_id, 'path' => 'B', 'actioned_by' => $marcus->id, 'actioned_at' => $l->assigned_at];
            }
            if ($l->responded_at) {
                $hist[] = ['tenant_id' => $T, 'lead_id' => $l->id, 'to_assignee_id' => $l->assignee_id, 'path' => 'A', 'actioned_by' => $l->assignee_id, 'actioned_at' => $l->responded_at];
                $life[] = ['tenant_id' => $T, 'lead_id' => $l->id, 'event' => 'Responded — '.$byId[$l->responded_by], 'source' => 'camplo', 'event_timestamp' => $l->responded_at, 'is_milestone' => false];
            }
            if ($l->has_external_lifecycle_events) {
                $crm = $l->source_system === 'gohighlevel' ? 'gohighlevel' : 'twenty_crm';
                $b = strtotime($l->responded_at ?? $l->received_at);
                foreach ([[7 * self::MIN, 'Person created', false], [28 * self::MIN, 'Opportunity created', false], [3.5 * self::HOUR, 'Opportunity → Meeting', false], [9 * self::HOUR, 'Appointment booked', true], [19 * self::HOUR, 'WON', true]] as [$off, $ev, $ms]) {
                    if ($b + $off > self::$now || ($ev === 'WON' && $h % 5 !== 0) || ($ev === 'Appointment booked' && $h % 2 !== 0)) {
                        break;
                    }
                    $life[] = ['tenant_id' => $T, 'lead_id' => $l->id, 'event' => $ev, 'source' => $crm, 'is_milestone' => $ms, 'event_timestamp' => self::at($b + $off)];
                }
            }
        }
        foreach (array_chunk($hist, 200) as $c) {
            DB::table('lead_assignment_history')->insert($c);
        }
        foreach (array_chunk($life, 200) as $c) {
            DB::table('lead_lifecycle_events')->insert($c);
        }
        $lead = fn (string $n) => current(array_filter($inserted, fn ($l) => $l->full_name === $n))->id;

        DB::table('notes')->insert([
            ['tenant_id' => $T, 'note_type' => 'campaign', 'entity_id' => $bf, 'author_id' => $tunde->id, 'content' => 'bf-bundle-b form looks broken on iOS Safari — submit button does nothing. Checking with the dev now.', 'created_at' => self::ago(40 * self::MIN), 'title' => null, 'meeting_date' => null, 'via' => null],
            ['tenant_id' => $T, 'note_type' => 'campaign', 'entity_id' => $bf, 'author_id' => $marcus->id, 'content' => 'Pausing the video ad set until CPL comes back under threshold. Carousel is carrying the campaign.', 'created_at' => self::ago(80 * self::MIN), 'title' => null, 'meeting_date' => null, 'via' => null],
            ['tenant_id' => $T, 'note_type' => 'campaign', 'entity_id' => $bf, 'author_id' => $sarah->id, 'content' => 'Called 11 leads from yesterday afternoon. 4 want the bundle delivered before Nov 20 — flagging for ops.', 'created_at' => self::ago(26 * self::HOUR), 'title' => null, 'meeting_date' => null, 'via' => null],
            ['tenant_id' => $T, 'note_type' => 'meeting', 'entity_id' => $bf, 'author_id' => $marcus->id, 'content' => 'Agreed to test a VIP early-access page; Kofi owns the Tally form; revisit budget Friday.', 'created_at' => self::ago(30 * self::HOUR), 'title' => 'Weekly campaign sync', 'meeting_date' => self::ago(30 * self::HOUR), 'via' => 'Manual'],
            ['tenant_id' => $T, 'note_type' => 'lead', 'entity_id' => $lead('Folake Ade'), 'author_id' => $sarah->id, 'content' => 'Asked for pricing twice by email. Prefers WhatsApp.', 'created_at' => self::ago(35 * self::MIN), 'title' => null, 'meeting_date' => null, 'via' => null],
            ['tenant_id' => $T, 'note_type' => 'campaign', 'entity_id' => $prop, 'author_id' => $amara->id, 'content' => 'VIP leads from lekki-offplan want site visits on weekends — we need Saturday cover.', 'created_at' => self::ago(5 * self::HOUR), 'title' => null, 'meeting_date' => null, 'via' => null],
        ]);
        foreach ([
            [$marcus, [$sarah], 'Please call Folake Ade before 3pm — she asked for pricing twice.', 'lead', $lead('Folake Ade'), 50 * self::MIN, 35],
            [$tunde, [$marcus], 'Twenty CRM sync is dropping the phone field for Tally leads. Can we raise it with support?', 'campaign', $bf, null, 120],
            [$marcus, [$tunde, $amara], 'Great work on response times this week. Let’s keep the Lekki VIP leads under 5 minutes.', null, null, null, 1200],
            [$marcus, [$kofi], 'Update the bf-vip-early form fields before Friday.', 'campaign', $bf, -2 * self::HOUR, 1560],
        ] as [$author, $to, $content, $att, $attId, $deadline, $minAgo]) {
            $tn = self::id('team_notes', ['tenant_id' => $T, 'author_id' => $author->id, 'content' => $content, 'attachment_type' => $att, 'attachment_id' => $attId, 'created_at' => self::ago($minAgo * self::MIN),
                'time_bound_deadline' => $deadline === null ? null : self::at(self::$now + $deadline), 'time_bound_status' => $deadline === null ? null : ($deadline < 0 ? 'expired' : 'pending')]);
            DB::table('team_note_recipients')->insert(array_map(fn ($u) => ['tenant_id' => $T, 'team_note_id' => $tn, 'user_id' => $u->id, 'read_at' => $minAgo > 60 ? self::ago(($minAgo - 30) * self::MIN) : null], $to));
        }

        // Integrations + cross-tool rules/breaches.
        $url = config('camplo.app_url');
        $sealed = fn (array $o) => Crypto::encrypt(json_encode($o), 'integration');
        $twenty = self::id('integrations', ['tenant_id' => $T, 'provider' => 'twenty_crm', 'connection_method' => 'api_key', 'api_key_encrypted' => Crypto::encrypt('twenty_demo_key_7f3a', 'integration'),
            'secrets_encrypted' => $sealed(['apiKey' => 'twenty_demo_key_7f3a']), 'config' => json_encode(['baseUrl' => 'https://crm.northbeam.demo']), 'active_modes' => json_encode(['receive', 'send', 'query']), 'status' => 'connected', 'last_verified_at' => self::ago(self::DAY)]);
        $tally = self::id('inbound_webhooks', ['tenant_id' => $T, 'source_label' => 'Tally', 'url' => 'pending', 'secret_encrypted' => Crypto::encrypt(Crypto::randomToken(24), 'webhook'), 'campaign_id' => $bf, 'last_received_at' => self::ago(4 * self::MIN)]);
        DB::table('inbound_webhooks')->where('id', $tally)->update(['url' => "{$url}/api/v1/hooks/{$tally}"]);
        DB::table('integrations')->where('id', $twenty)->update(['webhook_url' => "{$url}/api/v1/lifecycle/{$twenty}/".Crypto::randomToken(18)]);
        DB::table('integrations')->insert([
            ['tenant_id' => $T, 'provider' => 'tally', 'connection_method' => 'webhook', 'active_modes' => json_encode(['receive']), 'status' => 'connected', 'config' => json_encode(['inboundWebhookId' => $tally]), 'last_verified_at' => self::ago(self::DAY), 'api_key_encrypted' => null, 'secrets_encrypted' => null],
            ['tenant_id' => $T, 'provider' => 'umami', 'connection_method' => 'api_key', 'active_modes' => json_encode(['query']), 'status' => 'connected', 'config' => json_encode(['baseUrl' => 'https://api.umami.is/v1', 'websiteId' => '4fb7fa4c-5b46-438d-94b3-3a8fb9bc2e8b']), 'last_verified_at' => self::ago(self::DAY), 'api_key_encrypted' => Crypto::encrypt('umami_demo_key_91c2', 'integration'), 'secrets_encrypted' => $sealed(['apiKey' => 'umami_demo_key_91c2'])],
            ['tenant_id' => $T, 'provider' => 'gohighlevel', 'connection_method' => 'api_key', 'active_modes' => json_encode(['receive', 'send', 'query']), 'status' => 'failed', 'config' => json_encode(['locationId' => 've9EPM428h8vShlRW1KT']), 'last_verified_at' => self::ago(2 * self::DAY), 'api_key_encrypted' => Crypto::encrypt('ghl_demo_key_0b77', 'integration'), 'secrets_encrypted' => $sealed(['apiKey' => 'ghl_demo_key_0b77'])],
        ]);
        $rule = self::id('cross_tool_sla_rules', ['tenant_id' => $T, 'integration_id' => $twenty, 'rule_type' => 'not_contacted', 'threshold_value' => 24, 'threshold_unit' => 'hours', 'enabled' => true, 'notification_channels' => json_encode(['ai_panel', 'email'])]);
        DB::table('cross_tool_sla_rules')->insert([
            ['tenant_id' => $T, 'integration_id' => $twenty, 'rule_type' => 'not_proposal', 'threshold_value' => 72, 'threshold_unit' => 'hours', 'enabled' => true, 'notification_channels' => json_encode(['ai_panel'])],
            ['tenant_id' => $T, 'integration_id' => $twenty, 'rule_type' => 'no_activity', 'threshold_value' => 7, 'threshold_unit' => 'days', 'enabled' => false, 'notification_channels' => json_encode(['ai_panel'])],
        ]);
        DB::table('cross_tool_sla_breaches')->insert([
            ['tenant_id' => $T, 'lead_id' => $lead('Grace Lawal'), 'integration_id' => $twenty, 'rule_id' => $rule, 'breach_type' => 'not_contacted', 'hours_exceeded' => 3, 'detected_at' => self::ago(2 * self::HOUR)],
            ['tenant_id' => $T, 'lead_id' => $lead('Joy Ekpo'), 'integration_id' => $twenty, 'rule_id' => $rule, 'breach_type' => 'not_contacted', 'hours_exceeded' => 11, 'detected_at' => self::ago(5 * self::HOUR)],
        ]);
        $ib = self::id('inbound_webhooks', ['tenant_id' => $T, 'source_label' => 'Instantly — warm replies', 'url' => 'pending', 'secret_encrypted' => Crypto::encrypt(Crypto::randomToken(24), 'webhook'), 'campaign_id' => $bf, 'last_received_at' => self::ago(5 * self::HOUR)]);
        DB::table('inbound_webhooks')->where('id', $ib)->update(['url' => "{$url}/api/v1/hooks/{$ib}"]);

        // Campaign memory: operator changes with measured outcomes.
        $perf = fn ($lead, $conv, $cpl) => json_encode(['lead_count_7d' => $lead, 'avg_response_ms' => 260000, 'acknowledgment_rate' => 0.93, 'cpl' => $cpl, 'conversion_rate' => $conv]);
        $ch = fn ($type, $desc, $days, $b, $a) => self::id('campaign_changes', ['tenant_id' => $T, 'campaign_id' => $bf, 'change_type' => $type, 'change_description' => $desc, 'changed_by' => $marcus->id, 'changed_at' => self::ago($days * self::DAY), 'performance_before' => $b, 'performance_after' => $a]);
        $ch1 = $ch('audience', 'Narrowed audience to 25–44, Lagos + Abuja', 22, $perf(120, 0.031, 13100), $perf(131, 0.034, 12200));
        $ch2 = $ch('creative', 'Switched hero creative to 15s video', 16, $perf(131, 0.034, 12200), $perf(140, 0.026, 14300));
        $ch3 = $ch('creative', 'Reverted hero creative to carousel', 8, $perf(140, 0.026, 14300), $perf(128, 0.033, 12900));

        $rec = fn (array $r) => DB::table('campaign_recommendations')->insert(['tenant_id' => $T, 'diagnosis' => null, 'apply_action' => null, 'status' => 'surfaced', 'actioned_at' => null, ...$r,
            'evidence' => json_encode($r['evidence']), 'memory_references' => json_encode($r['memory_references']), 'apply_action' => isset($r['apply_action']) ? json_encode($r['apply_action']) : null]);
        $rec(['campaign_id' => $bf, 'level' => 2, 'action_text' => 'Cut bf-bundle-b spend by 20% until its webhook is fixed', 'why' => 'Conversion on bf-bundle-b halved while spend kept rising.',
            'evidence' => [['metric' => 'CPL', 'change' => '+28%', 'period' => '14 days'], ['metric' => 'bf-bundle-b conversion', 'change' => '9.1% → 4.2%', 'period' => '7 days'], ['metric' => 'bf-bundle-a conversion', 'change' => 'stable', 'period' => '7 days']],
            'diagnosis' => 'Traffic quality is unchanged (CTR flat). The drop starts at the page, the same day its webhook began dropping submissions.', 'expected_outcome' => 'Lower lead volume but CPL back under threshold.',
            'risk' => 'bf-bundle-b still feeds some top-of-funnel volume.', 'confidence' => 'medium', 'next_step' => 'Reduce by 20% and reassess after 7 days or 100 leads.', 'why_now' => 'CPL above threshold 5 days running.',
            'memory_references' => [$ch2, $ch3], 'apply_action' => ['type' => 'budget_adjustment', 'platform' => 'meta_ads', 'adjustment' => '-20%', 'status' => 'pending_v2'], 'surfaced_at' => self::ago(52 * self::MIN)]);
        $rec(['campaign_id' => $prop, 'level' => 3, 'action_text' => 'Test the previous Lekki page positioning against the new variant before changing media', 'why' => 'Lead volume is stable but qualified-lead rate fell 37% after the messaging change.',
            'evidence' => [['metric' => 'CTR', 'change' => 'unchanged', 'period' => '21 days'], ['metric' => 'Page conversion', 'change' => 'unchanged', 'period' => '21 days'], ['metric' => 'Qualified-lead rate', 'change' => '−37%', 'period' => '21 days']],
            'diagnosis' => 'Traffic → page → lead is healthy; qualification is where it breaks. The new "from $30k" positioning attracts browsers rather than buyers.',
            'expected_outcome' => 'Qualified-lead rate recovers toward the July baseline.', 'risk' => 'Old positioning produced ~12% fewer enquiries.', 'confidence' => 'high', 'next_step' => 'Run a 50/50 split for 10 days.', 'why_now' => 'Three weeks of consistent decline; sample now large enough.',
            'memory_references' => [], 'surfaced_at' => self::ago(3 * self::HOUR)]);
        $rec(['campaign_id' => null, 'level' => 4, 'action_text' => 'Stop optimising for lead volume — reallocate toward referral and webinar channels', 'why' => 'Your highest-volume channel is not your most efficient growth channel.',
            'evidence' => [['metric' => 'Paid social share of leads', 'change' => '61%', 'period' => '90 days'], ['metric' => 'Paid social share of qualified opportunities', 'change' => '28%', 'period' => '90 days'], ['metric' => 'Referral + webinar share of qualified opportunities', 'change' => '41%', 'period' => '90 days']],
            'diagnosis' => 'Cost per qualified opportunity on paid social is 3.2× referral. Volume masks the inefficiency in weekly reports.', 'expected_outcome' => 'Fewer total leads, more closed deals per dollar.',
            'risk' => 'Referral volume has a ceiling; paid social still seeds awareness.', 'confidence' => 'medium', 'next_step' => 'Shift 15% of paid social budget to referral incentives next month.', 'why_now' => 'Q4 budgets are being set this week.',
            'memory_references' => [$ch1], 'surfaced_at' => self::ago(20 * self::HOUR)]);
        $rec(['campaign_id' => $bf, 'level' => 2, 'action_text' => 'Refresh the Black Friday creative', 'why' => 'CTR has plateaued.', 'evidence' => [['metric' => 'CTR', 'change' => 'flat', 'period' => '14 days']],
            'expected_outcome' => 'Higher CTR.', 'risk' => 'May lower lead quality.', 'confidence' => 'low', 'next_step' => 'Test two new creatives.', 'why_now' => 'Plateau detected.', 'memory_references' => [],
            'status' => 'dismissed', 'surfaced_at' => self::ago(5 * self::DAY), 'actioned_at' => self::ago(5 * self::DAY)]);

        $ins = fn (array $x) => ['tenant_id' => $T, 'reassess_condition' => null, 'reassess_at' => null, ...$x];
        $bfn = 'Black Friday 2026';
        $pn = 'Lekki Property Enquiry — Q3';
        $wn = 'Growth Webinar Series';
        DB::table('insights')->insert([
            $ins(['campaign_id' => $bf, 'campaign_tag' => $bfn, 'type' => 'priority_flag', 'severity' => 'red', 'category' => 'SLA', 'generated_at' => self::ago(18 * self::MIN), 'dedupe_key' => "pf:{$bf}:seed",
                'observation' => 'Black Friday is failing at three handoffs at once — this is systemic, not one slow rep.',
                'evidence' => 'Unclaimed leads past threshold, acknowledged leads missing from Twenty CRM, and bf-bundle-b’s webhook silent for over 2 hours while ads keep spending.']),
            $ins(['campaign_id' => $bf, 'campaign_tag' => $bfn, 'type' => 'alert', 'severity' => 'red', 'category' => 'SLA', 'generated_at' => self::ago(34 * self::MIN), 'dedupe_key' => 'seed:crm',
                'observation' => 'Leads acknowledged this week have not been logged in your CRM.', 'evidence' => 'The oldest is 11 hours past your 24-hour CRM threshold. Your CRM rule is "moved to Contacted within 24h".']),
            $ins(['campaign_id' => $bf, 'campaign_tag' => $bfn, 'type' => 'alert', 'severity' => 'amber', 'category' => 'Spend', 'generated_at' => self::ago(52 * self::MIN), 'dedupe_key' => 'seed:cpl',
                'observation' => 'CPL on Black Friday has crossed your $12 threshold for the 5th day running.', 'evidence' => 'Ad CTR is unchanged, so traffic quality is not the cause — conversion on bf-bundle-b fell from 9.1% to 4.2%.']),
            $ins(['campaign_id' => $prop, 'campaign_tag' => $pn, 'type' => 'observation', 'severity' => 'amber', 'category' => 'Engagement', 'generated_at' => self::ago(2 * self::HOUR), 'dedupe_key' => 'seed:email',
                'observation' => 'Lekki leads open the first follow-up email but drop off at step 2.', 'evidence' => 'Step 1 open rate 61%, step 2 open rate 14%. The drop began when the step-2 subject line changed.']),
            $ins(['campaign_id' => $web, 'campaign_tag' => $wn, 'type' => 'observation', 'severity' => 'blue', 'category' => 'Performance', 'generated_at' => self::ago(3 * self::HOUR), 'dedupe_key' => 'seed:tue',
                'observation' => 'Webinar registrations peak on Tuesdays between 7 and 9pm.', 'evidence' => '38% of this month’s registrations arrived in that window, yet only one team member is typically online then.']),
            $ins(['campaign_id' => $prop, 'campaign_tag' => $pn, 'type' => 'win', 'severity' => 'green', 'category' => 'SLA', 'generated_at' => self::ago(4 * self::HOUR), 'dedupe_key' => 'seed:win',
                'observation' => 'Tunde responded to a Lekki VIP lead in 3m 42s — inside the 5-minute conversion window.', 'evidence' => 'That lead has already moved to "Meeting booked". Tunde’s best response time this month.']),
            $ins(['campaign_id' => $web, 'campaign_tag' => $wn, 'type' => 'win', 'severity' => 'green', 'category' => 'Performance', 'generated_at' => self::ago(26 * self::HOUR), 'dedupe_key' => 'seed:conv',
                'observation' => 'webinar-register now converts at 2.1× your account average.', 'evidence' => 'Registrations vs visits over the last 7 days, compared with the workspace average.']),
            $ins(['campaign_id' => $ref, 'campaign_tag' => 'Customer Referral Drive', 'type' => 'insufficient_evidence', 'severity' => 'blue', 'category' => 'Performance', 'generated_at' => self::ago(4 * self::HOUR), 'dedupe_key' => "ie:{$ref}",
                'observation' => 'Conversion on refer-a-friend has declined 8% — monitoring', 'evidence' => 'The campaign has generated too few leads this period to confidently recommend a change.',
                'reassess_condition' => '100 additional leads OR 7 days — whichever comes first', 'reassess_at' => self::at(self::$now + 5 * self::DAY)]),
        ]);

        // Retrospective for the completed campaign.
        DB::table('campaign_retrospectives')->insert(['tenant_id' => $T, 'campaign_id' => $sum]);
        CampaignService::generateRetrospective($T, $sum);

        $logs = [[15, $tunde, 'Responded to Grace Lawal in 3m 42s'], [40, $tunde, 'Tunde Omolayo added a note'], [58, null, 'Webhook on bf-bundle-b stopped receiving events'],
            [120, $marcus, 'Assigned Folake Ade to Sarah Okafor'], [300, $marcus, 'Logged creative change: paused video ad set'], [1560, $sarah, 'Responded to 11 leads'],
            [1620, null, 'Camplo raised a Priority Flag — 3 concurrent SLA breaches'], [3000, $kofi, 'Page bf-bundle-b redeployed by Kofi Mensah'], [4440, $marcus, 'CPL threshold set to 12000 by Marcus Adeyemi']];
        DB::table('campaign_logs')->insert(array_map(fn ($x) => ['tenant_id' => $T, 'campaign_id' => $bf, 'actor_id' => $x[1]->id ?? null, 'description' => $x[2], 'created_at' => self::ago($x[0] * self::MIN)], $logs));
        DB::table('notifications')->insert([
            ['tenant_id' => $T, 'kind' => 'sla_breach', 'description' => 'SLA breached — David Mensah', 'link' => '/leads/'.$lead('David Mensah'), 'created_at' => self::ago(12 * self::MIN), 'read_at' => null],
            ['tenant_id' => $T, 'kind' => 'webhook_offline', 'description' => 'bf-bundle-b webhook offline', 'link' => '/pages', 'created_at' => self::ago(58 * self::MIN), 'read_at' => null],
            ['tenant_id' => $T, 'kind' => 'insight', 'description' => 'Priority Flag raised on Black Friday 2026', 'link' => "/campaigns/{$bf}/insights", 'created_at' => self::ago(18 * self::MIN), 'read_at' => null],
            ['tenant_id' => $T, 'kind' => 'insight', 'description' => 'Tunde responded to a VIP lead in 3m 42s', 'link' => "/campaigns/{$prop}/insights", 'created_at' => self::ago(4 * self::HOUR), 'read_at' => self::ago(3 * self::HOUR)],
        ]);
        DB::table('chat_messages')->insert([
            ['tenant_id' => $T, 'user_id' => $marcus->id, 'role' => 'user', 'content' => 'Why did Black Friday CPL jump this week?', 'created_at' => self::ago(26 * self::HOUR), 'workload_level' => null],
            ['tenant_id' => $T, 'user_id' => null, 'role' => 'assistant', 'workload_level' => 'standard', 'created_at' => self::ago(26 * self::HOUR - 20),
                'content' => "CPL rose about **28%**. Ad CTR is flat, so traffic quality is not the cause. The drop is at the page: [bf-bundle-b](#/pages) conversion fell from 9.1% to 4.2% on the same day its webhook began dropping submissions.\n\nCampaign memory also shows the video creative test (16 days ago) lowered conversion, so I would not refresh creative again yet."],
        ]);
        DB::table('workspace_logs')->insert(['tenant_id' => $T, 'actor_id' => $marcus->id, 'description' => 'Workspace created by Marcus Adeyemi', 'created_at' => self::ago(90 * self::DAY)]);

        return $T;
    }
}
