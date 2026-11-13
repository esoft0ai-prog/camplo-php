<?php

namespace App\Services;

use App\Support\Crypto;
use App\Support\Ctx;
use App\Support\Fail;
use App\Support\Fmt;
use App\Support\Sql;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Integrations, webhooks, AI provider, Telegram and notification settings. */
final class SettingsService
{
    private static function prefs(object $t): array
    {
        return Fmt::json($t->notification_prefs) ?? [];
    }

    public static function notifications(Ctx $ctx): array
    {
        $t = $ctx->tenant;
        $p = self::prefs($t);

        return [
            'dailyDigest' => (bool) $t->daily_summary_enabled, 'dailyDigestTime' => substr($t->daily_summary_time, 0, 5),
            'slaBreachAlerts' => $p['slaBreach'] ?? null, 'slaBreachChannel' => $p['slaChannel'] ?? null,
            'earlyWarning' => $p['earlyWarning'] ?? null, 'budgetAlerts' => $p['budget'] ?? null,
            'webhookOffline' => $p['webhookOffline'] ?? null, 'notificationEmail' => $t->notification_email,
        ];
    }

    public static function patchNotifications(Ctx $ctx, array $in): array
    {
        $ctx->assertPerm('notifications.manage');
        $p = self::prefs($ctx->tenant);
        foreach (['slaBreachAlerts' => 'slaBreach', 'earlyWarning' => 'earlyWarning', 'budgetAlerts' => 'budget', 'webhookOffline' => 'webhookOffline'] as $k => $f) {
            if (array_key_exists($k, $in) && $in[$k] !== null) {
                $p[$f] = (bool) $in[$k];
            }
        }
        if (! empty($in['slaBreachChannel'])) {
            $p['slaChannel'] = $in['slaBreachChannel'];
        }
        $set = ['notification_prefs' => json_encode((object) $p), 'urgent_alerts_enabled' => (bool) ($p['slaBreach'] ?? true)];
        if (array_key_exists('dailyDigest', $in) && $in['dailyDigest'] !== null) {
            $set['daily_summary_enabled'] = (bool) $in['dailyDigest'];
        }
        if (! empty($in['dailyDigestTime'])) {
            if (! preg_match('/^\d{2}:\d{2}$/', $in['dailyDigestTime'])) {
                throw Fail::bad('Use HH:MM (UTC).');
            }
            $set['daily_summary_time'] = $in['dailyDigestTime'].':00';
        }
        if (! empty($in['notificationEmail'])) {
            $set['notification_email'] = $in['notificationEmail'];
        }
        DB::table('tenants')->where('id', $ctx->tenantId)->update($set);
        $ctx->reloadTenant();

        return self::notifications($ctx);
    }

    // ------------------------------------------------------------- integrations

    public static function catalog(): array
    {
        static $c = null;

        return $c ??= json_decode(file_get_contents(app_path('Domain/catalog.json')), true);
    }

    public static function catalogEntry(string $provider): ?array
    {
        foreach (self::catalog() as $c) {
            if ($c['provider'] === $provider) {
                return $c;
            }
        }

        return null;
    }

    private static function readSecrets(?object $r): array
    {
        if (! $r) {
            return [];
        }
        try {
            if (! $r->secrets_encrypted) {
                return $r->api_key_encrypted ? ['apiKey' => Crypto::decrypt($r->api_key_encrypted)] : [];
            }

            return json_decode(Crypto::decrypt($r->secrets_encrypted), true) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /** Catalog + this workspace's state: settings, masked secrets, webhook URLs and setup steps. */
    public static function listIntegrations(Ctx $ctx): array
    {
        $ctx->assertPerm('integrations.manage');
        $rows = DB::table('integrations')->where('tenant_id', $ctx->tenantId)->get()->map(function ($r) {
            $r->cfg = Fmt::json($r->config) ?? [];

            return $r;
        })->keyBy('provider');
        $hookIds = $rows->map(fn ($r) => $r->cfg['inboundWebhookId'] ?? null)->filter()->values()->all();
        $hooks = $hookIds ? DB::table('inbound_webhooks')->where('tenant_id', $ctx->tenantId)->whereIn('id', $hookIds)->get()->keyBy('id') : collect();
        $url = config('camplo.app_url');

        return array_map(function ($c) use ($rows, $hooks, $url) {
            $r = $rows[$c['provider']] ?? null;
            $sec = self::readSecrets($r);
            $hook = $r ? ($hooks[$r->cfg['inboundWebhookId'] ?? ''] ?? null) : null;
            $hookSecret = $hook ? Crypto::decrypt($hook->secret_encrypted) : null;
            $soon = ! empty($c['comingSoon']);
            $connected = ! $soon && $r && $r->status === 'connected';
            $fields = $c['fields'];
            $settings = $secrets = [];
            foreach ($fields as $f) {
                if ($f['type'] === 'secret') {
                    $secrets[$f['key']] = ! empty($sec[$f['key']]) ? Crypto::mask($sec[$f['key']]) : null;
                } else {
                    $settings[$f['key']] = $r->cfg[$f['key']] ?? null;
                }
            }

            return [
                'provider' => $c['provider'], 'name' => $c['name'], 'category' => $c['category'], 'blurb' => $c['blurb'], 'methods' => $c['methods'], 'modes' => $c['modes'], 'fields' => $fields,
                'webhookKinds' => $c['webhooks'], 'signing' => $c['signing'] ?? null, 'setup' => $c['setup'], 'docsUrl' => $c['docsUrl'] ?? null, 'comingSoon' => $soon,
                'id' => $r->id ?? null, 'status' => $soon ? 'coming_soon' : ($r->status ?? 'not_connected'),
                'connectionMethod' => $r->connection_method ?? null, 'activeModes' => $r ? (Fmt::json($r->active_modes) ?? []) : [],
                'settings' => (object) $settings, 'secrets' => (object) $secrets, 'apiKeyMasked' => ! empty($sec['apiKey']) ? Crypto::mask($sec['apiKey']) : null,
                'webhooks' => $connected ? [
                    'leads' => $hook && $hookSecret ? "{$url}/api/v1/hooks/{$hook->id}?token=".rawurlencode($hookSecret) : null,
                    'leadsBase' => $hook ? "{$url}/api/v1/hooks/{$hook->id}" : null,
                    'signingSecret' => $hook && in_array($c['signing'] ?? null, ['tally', 'typeform', 'camplo'], true) ? $hookSecret : null,
                    'lifecycle' => in_array('lifecycle', $c['webhooks'], true) ? ($r->webhook_url ?? null) : null,
                    'campaignId' => $hook->campaign_id ?? null, 'lastReceivedAt' => Fmt::iso($hook->last_received_at ?? null),
                ] : null,
                'lastVerifiedAt' => Fmt::iso($r->last_verified_at ?? null),
            ];
        }, self::catalog());
    }

    public static function connectIntegration(Ctx $ctx, string $provider, array $in): ?array
    {
        $ctx->assertPerm('integrations.manage');
        $cat = self::catalogEntry($provider);
        if (! $cat) {
            throw Fail::notFound('Unknown integration.');
        }
        if (! empty($cat['comingSoon'])) {
            throw Fail::bad("{$cat['name']} is coming soon.");
        }
        $existing = DB::table('integrations')->where('tenant_id', $ctx->tenantId)->where('provider', $provider)->first();
        if (! empty($cat['isTool']) && (! $existing || $existing->status !== 'connected')) {
            $tools = array_column(array_filter(self::catalog(), fn ($c) => ! empty($c['isTool'])), 'provider');
            $n = DB::table('integrations')->where('tenant_id', $ctx->tenantId)->where('status', 'connected')->whereIn('provider', $tools)->count();
            $lim = Platform::planLimits($ctx->plan)['connectedTools'];
            if ($lim >= 0 && $n >= $lim) {
                throw Fail::planLimit($ctx->plan === 'starter' ? 'Connected tools are available on Growth.' : 'Growth includes one connected tool. Upgrade to Watchtower for unlimited tools.', $ctx->plan === 'starter' ? 'growth' : 'watchtower');
            }
        }
        // Submitted fields over what is stored; blank keeps the stored value.
        $given = [];
        foreach ([...(! empty($in['apiKey']) ? ['apiKey' => $in['apiKey']] : []), ...($in['fields'] ?? [])] as $k => $v) {
            if (is_string($v) && trim($v) !== '') {
                $given[$k] = trim($v);
            }
        }
        $secrets = self::readSecrets($existing);
        $cfg = $existing ? (Fmt::json($existing->config) ?? []) : [];
        foreach ($cat['fields'] as $f) {
            $v = $given[$f['key']] ?? null;
            if ($v !== null) {
                if ($f['type'] === 'url' && ! preg_match('#^https?://\S+$#', $v)) {
                    throw Fail::bad("{$f['label']} must be a full URL starting with https://");
                }
                if ($f['type'] === 'secret') {
                    $secrets[$f['key']] = $v;
                } else {
                    $cfg[$f['key']] = rtrim($v, '/');
                }
            } elseif (! empty($f['default']) && empty($cfg[$f['key']]) && $f['type'] !== 'secret') {
                $cfg[$f['key']] = $f['default'];
            }
            $have = $f['type'] === 'secret' ? ($secrets[$f['key']] ?? null) : ($cfg[$f['key']] ?? null);
            if (! empty($f['required']) && ! $have) {
                throw Fail::bad("{$cat['name']}: {$f['label']} is required.");
            }
        }
        if ($provider === 'gohighlevel' && ! empty($secrets['apiKey']) && empty($cfg['locationId'])) {
            throw Fail::bad('GoHighLevel: add the Location ID to use the API token.');
        }
        if ($provider === 'mailchimp' && ! empty($secrets['apiKey']) && ! preg_match('/-[a-z]+\d+$/', $secrets['apiKey'])) {
            throw Fail::bad('Mailchimp: the API key should end with your data centre, e.g. -us21.');
        }
        $campaignId = $in['campaignId'] ?? null;
        if ($campaignId && ! DB::table('campaigns')->where('tenant_id', $ctx->tenantId)->where('id', $campaignId)->exists()) {
            throw Fail::bad('Campaign not found.');
        }
        if (in_array('leads', $cat['webhooks'], true)) {
            $hook = ! empty($cfg['inboundWebhookId']) ? DB::table('inbound_webhooks')->where('tenant_id', $ctx->tenantId)->where('id', $cfg['inboundWebhookId'])->first() : null;
            if ($hook) {
                DB::table('inbound_webhooks')->where('id', $hook->id)->update(['status' => 'active', ...(array_key_exists('campaignId', $in) ? ['campaign_id' => $campaignId] : [])]);
            } else {
                $hid = Sql::insertId('inbound_webhooks', ['tenant_id' => $ctx->tenantId, 'source_label' => $cat['name'], 'url' => 'pending', 'secret_encrypted' => Crypto::encrypt(Crypto::randomToken(24), 'webhook'), 'campaign_id' => $campaignId]);
                DB::table('inbound_webhooks')->where('id', $hid)->update(['url' => config('camplo.app_url')."/api/v1/hooks/{$hid}"]);
                $cfg['inboundWebhookId'] = $hid;
            }
        }
        $hasSecret = (bool) $secrets;
        $method = $hasSecret ? 'api_key' : (in_array('webhook', $cat['methods'], true) ? 'webhook' : $cat['methods'][0]);
        $modes = array_values(array_filter($cat['modes'], fn ($m) => $m === 'receive' ? (bool) $cat['webhooks'] : ($m === 'query' ? $hasSecret : true)));
        $values = [
            'tenant_id' => $ctx->tenantId, 'provider' => $provider, 'connection_method' => $method,
            'api_key_encrypted' => ! empty($secrets['apiKey']) ? Crypto::encrypt($secrets['apiKey'], 'integration') : null,
            'secrets_encrypted' => $hasSecret ? Crypto::encrypt(json_encode($secrets), 'integration') : null,
            'config' => json_encode((object) $cfg), 'status' => 'connected', 'active_modes' => json_encode($modes), 'updated_at' => now(),
        ];
        if ($existing) {
            DB::table('integrations')->where('id', $existing->id)->update($values);
            $id = $existing->id;
        } else {
            $id = Sql::insertId('integrations', $values);
        }
        if (in_array('lifecycle', $cat['webhooks'], true) && ! DB::table('integrations')->where('id', $id)->value('webhook_url')) {
            DB::table('integrations')->where('id', $id)->update(['webhook_url' => config('camplo.app_url')."/api/v1/lifecycle/{$id}/".Crypto::randomToken(18)]);
        }
        Effects::logWorkspace($ctx->tenantId, $ctx->user->id, 'Integration '.($existing && $existing->status === 'connected' ? 'updated' : 'connected').": {$cat['name']}");

        foreach (self::listIntegrations($ctx) as $i) {
            if ($i['provider'] === $provider) {
                return $i;
            }
        }

        return null;
    }

    /** @var (callable(string, array, array): array)|null test hook replacing the provider credential check */
    public static $probe = null;

    public static function verifyIntegration(Ctx $ctx, string $provider): array
    {
        $ctx->assertPerm('integrations.manage');
        $r = DB::table('integrations')->where('tenant_id', $ctx->tenantId)->where('provider', $provider)->first();
        if (! $r) {
            throw Fail::notFound('Integration not connected.');
        }
        $cfg = Fmt::json($r->config) ?? [];
        $res = (self::$probe ?? self::probe(...))($provider, $cfg, self::readSecrets($r));
        DB::table('integrations')->where('id', $r->id)->update(['status' => $res['ok'] ? 'connected' : 'failed', 'last_verified_at' => now()]);

        return ['ok' => $res['ok'], 'status' => $res['ok'] ? 'connected' : 'failed', 'message' => $res['message']];
    }

    /** Credential check against each provider's API (read-only calls). */
    private static function probe(string $provider, array $cfg, array $sec): array
    {
        $get = function (string $url, array $headers = []) {
            try {
                $res = Http::withHeaders(['Accept' => 'application/json', ...$headers])->timeout(10)->get($url);

                return $res->successful() ? ['ok' => true, 'message' => 'Credentials accepted.'] : ['ok' => false, 'message' => "The provider answered {$res->status()}. Check the values and try again."];
            } catch (Throwable $e) {
                return ['ok' => false, 'message' => "Could not reach the provider ({$e->getMessage()})."];
            }
        };
        $key = $sec['apiKey'] ?? '';
        $enc = fn ($k) => rawurlencode((string) ($cfg[$k] ?? ''));

        return match ($provider) {
            'brevo' => $get('https://api.brevo.com/v3/account', ['api-key' => $key]),
            'activecampaign' => $get(($cfg['baseUrl'] ?? '').'/api/3/users/me', ['Api-Token' => $key]),
            'mailchimp' => $get('https://'.substr($key, strrpos($key, '-') + 1).'.api.mailchimp.com/3.0/lists/'.$enc('audienceId'), ['Authorization' => 'Basic '.base64_encode("camplo:{$key}")]),
            'umami' => $get(($cfg['baseUrl'] ?? '').'/websites/'.$enc('websiteId'), ['x-umami-api-key' => $key, 'Authorization' => "Bearer {$key}"]),
            'meta_ads' => $get('https://graph.facebook.com/v21.0/'.$enc('adAccountId').'?fields=name&access_token='.rawurlencode($sec['accessToken'] ?? '')),
            'twenty_crm' => $get(($cfg['baseUrl'] ?? '').'/rest/people?limit=1', ['Authorization' => "Bearer {$key}"]),
            'notifuse' => $get(($cfg['baseUrl'] ?? '').'/api/workspaces.get?id='.$enc('workspaceId'), ['Authorization' => "Bearer {$key}"]),
            'systeme_io' => $key ? $get('https://api.systeme.io/api/contacts?limit=10', ['X-API-Key' => $key]) : ['ok' => true, 'message' => 'Webhook connection — send a test submission to confirm.'],
            'gohighlevel' => $key ? $get('https://services.leadconnectorhq.com/locations/'.$enc('locationId'), ['Authorization' => "Bearer {$key}", 'Version' => '2021-07-28']) : ['ok' => true, 'message' => 'Webhook connection — send a test contact to confirm.'],
            'google_ads' => (function () use ($cfg, $sec) {
                try {
                    $res = Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', ['client_id' => $cfg['clientId'] ?? '', 'client_secret' => $sec['clientSecret'] ?? '', 'refresh_token' => $sec['refreshToken'] ?? '', 'grant_type' => 'refresh_token']);

                    return $res->successful() ? ['ok' => true, 'message' => 'OAuth credentials accepted.'] : ['ok' => false, 'message' => "Google rejected the OAuth credentials ({$res->status()})."];
                } catch (Throwable $e) {
                    return ['ok' => false, 'message' => "Could not reach Google ({$e->getMessage()})."];
                }
            })(),
            default => ['ok' => true, 'message' => 'Webhook connection — send a test event to confirm it arrives.'],
        };
    }

    public static function disconnectIntegration(Ctx $ctx, string $provider): array
    {
        $ctx->assertPerm('integrations.manage');
        $r = DB::table('integrations')->where('tenant_id', $ctx->tenantId)->where('provider', $provider)->first();
        if (! $r) {
            return ['ok' => true];
        }
        DB::table('integrations')->where('id', $r->id)->update(['status' => 'not_connected', 'api_key_encrypted' => null, 'secrets_encrypted' => null, 'oauth_access_token_encrypted' => null,
            'oauth_refresh_token_encrypted' => null, 'active_modes' => '[]', 'updated_at' => now()]);
        $hook = (Fmt::json($r->config) ?? [])['inboundWebhookId'] ?? null;
        if ($hook) {
            DB::table('inbound_webhooks')->where('tenant_id', $ctx->tenantId)->where('id', $hook)->update(['status' => 'inactive']);
        }
        Effects::logWorkspace($ctx->tenantId, $ctx->user->id, 'Integration disconnected: '.(self::catalogEntry($provider)['name'] ?? $provider));

        return ['ok' => true];
    }

    // ------------------------------------------------------------- webhooks

    public static function listInbound(Ctx $ctx): array
    {
        // Secrets are write-only: shown once at creation, never listed.
        return DB::table('inbound_webhooks as w')->leftJoin('campaigns', 'campaigns.id', '=', 'w.campaign_id')->where('w.tenant_id', $ctx->tenantId)->orderBy('w.created_at')
            ->get(['w.*', 'campaigns.name as cname'])->map(fn ($w) => ['id' => $w->id, 'sourceLabel' => $w->source_label, 'url' => $w->url, 'lastReceivedAt' => Fmt::iso($w->last_received_at),
                'status' => $w->status, 'campaignId' => $w->campaign_id, 'campaignName' => $w->cname, 'createdAt' => Fmt::iso($w->created_at)])->all();
    }

    public static function createInbound(Ctx $ctx, string $label, ?string $campaignId): array
    {
        $ctx->assertPerm('integrations.manage');
        if ($campaignId) {
            CampaignService::load($ctx, $campaignId);
        }
        $secret = Crypto::randomToken(24);
        $id = Sql::insertId('inbound_webhooks', ['tenant_id' => $ctx->tenantId, 'source_label' => trim($label), 'url' => 'pending', 'secret_encrypted' => Crypto::encrypt($secret, 'webhook'), 'campaign_id' => $campaignId]);
        $url = config('camplo.app_url')."/api/v1/hooks/{$id}";
        DB::table('inbound_webhooks')->where('id', $id)->update(['url' => $url]);

        return ['id' => $id, 'sourceLabel' => trim($label), 'url' => $url, 'secret' => $secret, 'status' => 'active'];
    }

    public static function deleteInbound(Ctx $ctx, string $id): array
    {
        $ctx->assertPerm('integrations.manage');
        DB::table('inbound_webhooks')->where('tenant_id', $ctx->tenantId)->where('id', $id)->delete();

        return ['ok' => true];
    }

    public const OUTBOUND_EVENTS = ['lead.responded', 'lead.assigned', 'lead.received', 'campaign.completed', 'sla.breached', 'deployment.ready'];

    public static function listOutbound(Ctx $ctx): array
    {
        return DB::table('outbound_webhooks')->where('tenant_id', $ctx->tenantId)->orderBy('created_at')->get()
            ->map(fn ($w) => ['id' => $w->id, 'destinationUrl' => $w->destination_url, 'eventTrigger' => $w->event_trigger, 'lastSentAt' => Fmt::iso($w->last_sent_at), 'status' => $w->status, 'signed' => (bool) $w->secret_encrypted])->all();
    }

    public static function createOutbound(Ctx $ctx, array $in): array
    {
        $ctx->assertPerm('integrations.manage');
        $u = parse_url($in['destinationUrl']);
        if (! $u || empty($u['scheme']) || empty($u['host'])) {
            throw Fail::bad('Enter a valid https URL.');
        }
        if ($u['scheme'] !== 'https' && app()->environment('production')) {
            throw Fail::bad('Outbound webhooks must use https.');
        }
        if (preg_match('/^(localhost|127\.|10\.|192\.168\.|169\.254\.)/', $u['host'])) {
            throw Fail::bad('Private network destinations are not allowed.');
        }
        $id = Sql::insertId('outbound_webhooks', ['tenant_id' => $ctx->tenantId, 'destination_url' => $in['destinationUrl'], 'event_trigger' => $in['eventTrigger'],
            'secret_encrypted' => ! empty($in['secret']) ? Crypto::encrypt($in['secret'], 'webhook') : null]);

        return ['id' => $id, 'destinationUrl' => $in['destinationUrl'], 'eventTrigger' => $in['eventTrigger'], 'status' => 'active'];
    }

    public static function deleteOutbound(Ctx $ctx, string $id): array
    {
        $ctx->assertPerm('integrations.manage');
        DB::table('outbound_webhooks')->where('tenant_id', $ctx->tenantId)->where('id', $id)->delete();

        return ['ok' => true];
    }

    // ------------------------------------------------------------- AI provider

    private static function aiRow(Ctx $ctx): object
    {
        $r = DB::table('ai_provider_configs')->where('tenant_id', $ctx->tenantId)->first();
        if (! $r) {
            DB::table('ai_provider_configs')->insert(['tenant_id' => $ctx->tenantId]);
            $r = DB::table('ai_provider_configs')->where('tenant_id', $ctx->tenantId)->first();
        }

        return $r;
    }

    private static function maskEnc(?string $enc): ?string
    {
        try {
            return $enc ? Crypto::mask(Crypto::decrypt($enc)) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function aiProvider(Ctx $ctx): array
    {
        $ctx->assertPerm('ai.manage');
        $r = self::aiRow($ctx);
        $usage = Metrics::advancedUsage($ctx->tenantId, $ctx->plan);
        $learned = DB::table('hindsight_retain_log')->where('tenant_id', $ctx->tenantId)->whereIn('event_type', ['preference', 'chat_fact'])->orderByDesc('retained_at')->limit(10)
            ->get(['event_type', 'content_summary', 'retained_at'])->map(fn ($x) => ['type' => $x->event_type, 'text' => $x->content_summary, 'at' => Fmt::iso($x->retained_at)])->all();

        return [
            'primary' => ['provider' => $r->primary_provider, 'modelName' => $r->primary_model_name, 'apiKeyMasked' => self::maskEnc($r->primary_api_key_encrypted), 'status' => $r->primary_status],
            'fallback' => ['enabled' => (bool) $r->fallback_enabled, 'provider' => $r->fallback_provider, 'modelName' => $r->fallback_model_name, 'apiKeyMasked' => self::maskEnc($r->fallback_api_key_encrypted), 'status' => $r->fallback_status],
            'usingFallback' => (bool) $r->using_fallback, 'refreshIntervalMinutes' => (int) $r->refresh_interval_minutes, 'eventTriggers' => Fmt::json($r->event_triggers) ?? [],
            'camploProvidedAi' => (bool) Platform::get()['ai']['openRouterApiKey'], 'advancedUsage' => min(1, $usage),
            'memory' => ['engine' => config('camplo.hindsight_api_url') ? 'hindsight' : 'built_in', 'total' => DB::table('hindsight_retain_log')->where('tenant_id', $ctx->tenantId)->count(), 'learned' => $learned],
        ];
    }

    public static function patchAiProvider(Ctx $ctx, array $p): array
    {
        $ctx->assertPerm('ai.manage');
        $r = self::aiRow($ctx);
        $set = ['updated_at' => now()];
        foreach (['primary', 'fallback'] as $which) {
            $x = $p[$which] ?? null;
            if (! is_array($x)) {
                continue;
            }
            if ($which === 'fallback' && array_key_exists('enabled', $x) && $x['enabled'] !== null) {
                $set['fallback_enabled'] = (bool) $x['enabled'];
            }
            if (array_key_exists('provider', $x)) {
                $set["{$which}_provider"] = $x['provider'];
            }
            if (array_key_exists('modelName', $x)) {
                $set["{$which}_model_name"] = trim((string) $x['modelName']) ?: null;
            }
            if (array_key_exists('apiKey', $x)) {
                $set["{$which}_api_key_encrypted"] = $x['apiKey'] ? Crypto::encrypt(trim($x['apiKey']), 'ai') : null;
                $set["{$which}_status"] = 'not_connected';
            }
        }
        if (isset($p['refreshIntervalMinutes'])) {
            $set['refresh_interval_minutes'] = max(5, (int) round($p['refreshIntervalMinutes']));
        }
        if (isset($p['eventTriggers'])) {
            $set['event_triggers'] = json_encode(array_values(array_filter($p['eventTriggers'], fn ($t) => in_array($t, ['sla_breach', 'webhook_silence', 'lead_batch', 'budget_threshold'], true))));
        }
        DB::table('ai_provider_configs')->where('id', $r->id)->update($set);

        return self::aiProvider($ctx);
    }

    public static function verifyAi(Ctx $ctx, string $which): array
    {
        $ctx->assertPerm('ai.manage');
        $r = self::aiRow($ctx);
        $provider = $r->{"{$which}_provider"};
        $key = $r->{"{$which}_api_key_encrypted"};
        if (! $provider || ! $key) {
            throw Fail::bad('Choose a provider and enter an API key first.');
        }
        $ok = Ai::verifyProvider($provider, $r->{"{$which}_model_name"}, Crypto::decrypt($key));
        DB::table('ai_provider_configs')->where('id', $r->id)->update($which === 'primary' ? ['primary_status' => $ok ? 'connected' : 'failed', 'using_fallback' => false] : ['fallback_status' => $ok ? 'connected' : 'failed']);

        return ['ok' => $ok, 'status' => $ok ? 'connected' : 'failed'];
    }

    // ------------------------------------------------------------- Telegram

    public static function telegram(Ctx $ctx): array
    {
        $ctx->assertPerm('telegram.manage');
        $t = DB::table('telegram_connections')->where('tenant_id', $ctx->tenantId)->first();
        $code = Telegram::linkCodeFor($ctx->user->id);
        $camploBot = (bool) Platform::get()['telegram']['botToken'];

        return $t
            ? ['connected' => (bool) $t->verified, 'botUsername' => $t->bot_username, 'tokenMasked' => self::maskEnc($t->bot_token_encrypted), 'criticalAlertsEnabled' => (bool) $t->critical_alerts_enabled, 'dailyDigestEnabled' => (bool) $t->daily_digest_enabled, 'camploBot' => false, 'linkCode' => $code]
            : ['connected' => $camploBot, 'botUsername' => null, 'tokenMasked' => null, 'criticalAlertsEnabled' => true, 'dailyDigestEnabled' => false, 'camploBot' => $camploBot, 'linkCode' => $code];
    }

    public static function verifyTelegram(Ctx $ctx, string $token): array
    {
        $ctx->assertPerm('telegram.manage');
        $token = trim($token);
        $res = Telegram::verifyBot($token);
        $values = ['bot_token_encrypted' => Crypto::encrypt($token, 'telegram'), 'bot_username' => $res['username'] ?? null, 'verified' => $res['ok']];
        DB::table('telegram_connections')->upsert([['tenant_id' => $ctx->tenantId, ...$values]], ['tenant_id'], array_keys($values));
        if ($res['ok']) {
            Telegram::registerWebhook($token, $ctx->tenantId);
        }

        return ['ok' => $res['ok'], 'botUsername' => $res['username'] ?? null];
    }

    public static function patchTelegram(Ctx $ctx, array $p): array
    {
        $ctx->assertPerm('telegram.manage');
        $set = [];
        foreach (['criticalAlertsEnabled' => 'critical_alerts_enabled', 'dailyDigestEnabled' => 'daily_digest_enabled'] as $k => $col) {
            if (array_key_exists($k, $p) && $p[$k] !== null) {
                $set[$col] = (bool) $p[$k];
            }
        }
        if ($set) {
            DB::table('telegram_connections')->where('tenant_id', $ctx->tenantId)->update($set);
        }

        return self::telegram($ctx);
    }

    public static function disconnectTelegram(Ctx $ctx): array
    {
        $ctx->assertPerm('telegram.manage');
        DB::table('telegram_connections')->where('tenant_id', $ctx->tenantId)->delete();

        return ['ok' => true];
    }
}
