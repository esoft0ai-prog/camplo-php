<?php

namespace App\Services;

use App\Domain\Rules;
use App\Support\Crypto;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Platform settings the Super Admin can change at runtime (branding, pricing & limits, payments, email, AI,
 * Telegram, signup). Each section is one `platform_settings` row whose values override the environment; empty
 * values fall back to env, so a fresh deploy works from env alone. Secret fields are sealed with Crypto.
 */
final class Platform
{
    public const SECTIONS = ['branding', 'pricing', 'billing', 'email', 'ai', 'telegram', 'signup'];

    public const SECRETS = [
        'billing' => ['purpose' => 'integration', 'fields' => ['polarAccessToken', 'polarWebhookSecret']],
        'email' => ['purpose' => 'integration', 'fields' => ['smtpPass', 'resendApiKey']],
        'ai' => ['purpose' => 'ai', 'fields' => ['openRouterApiKey']],
        'telegram' => ['purpose' => 'telegram', 'fields' => ['botToken', 'webhookSecret']],
    ];

    private const TTL = 15;

    private static ?array $cache = null;

    private static float $at = 0;

    private static array $stored = [];

    public static function invalidate(): void
    {
        self::$cache = null;
    }

    private static function perPlan(callable $f): array
    {
        $o = [];
        foreach (Rules::PLANS as $p) {
            $o[$p] = $f($p);
        }

        return $o;
    }

    public static function defaults(): array
    {
        $c = config('camplo');
        $from = $c['email_from'];

        return [
            'branding' => ['productName' => 'Camplo', 'tagline' => 'See everything. Miss nothing.', 'supportEmail' => $c['support_email'], 'emailFromName' => preg_replace('/\s*<.*$/', '', $from) ?: 'Camplo'],
            'pricing' => [
                'currency' => 'USD',
                'plans' => self::perPlan(fn ($p) => ['price' => Rules::PLAN_PRICE[$p], 'deepBudgetUsd' => Rules::DEEP_BUDGET_USD[$p], 'limits' => Rules::PLAN_LIMITS[$p]]),
                'addOns' => ['extraDeploymentMonthly' => 15],
            ],
            'billing' => [
                'polarAccessToken' => $c['polar_access_token'], 'polarWebhookSecret' => $c['polar_webhook_secret'], 'polarOrganizationId' => $c['polar_organization_id'],
                'polarApiUrl' => $c['polar_api_url'], 'productIds' => self::perPlan(fn ($p) => $c['polar_product_ids'][$p] ?? ''),
                'checkoutUrls' => self::perPlan(fn ($p) => $c['polar_checkout_urls'][$p] ?? ''),
                'allowDirectPlanChange' => (bool) $c['allow_direct_plan_change'],
            ],
            'email' => ['smtpHost' => $c['smtp']['host'], 'smtpPort' => $c['smtp']['port'], 'smtpUser' => $c['smtp']['user'], 'smtpPass' => $c['smtp']['pass'], 'fromAddress' => $from, 'resendApiKey' => $c['resend_api_key']],
            'ai' => ['openRouterApiKey' => $c['openrouter_api_key'], 'defaultProvider' => $c['default_ai_provider'], 'models' => $c['models']],
            'telegram' => ['botToken' => $c['telegram_bot_token'], 'webhookSecret' => $c['telegram_webhook_secret']],
            'signup' => ['autoActivate' => (bool) $c['auto_activate'], 'defaultPlan' => 'growth'],
        ];
    }

    private static function isAssoc(mixed $v): bool
    {
        return is_array($v) && ($v === [] || array_keys($v) !== range(0, count($v) - 1));
    }

    /** Deep-merge: override wins wherever it holds a real value ('' / null keep the default). */
    private static function merge(mixed $base, mixed $over): mixed
    {
        if (! self::isAssoc($over)) {
            return $base;
        }
        $out = $base;
        foreach ($over as $k => $v) {
            if ($v === '' || $v === null) {
                continue;
            }
            $b = $out[$k] ?? null;
            $out[$k] = self::isAssoc($b) && is_array($v) ? self::merge($b, $v) : $v;
        }

        return $out;
    }

    public static function get(): array
    {
        if (self::$cache !== null && microtime(true) - self::$at < self::TTL) {
            return self::$cache;
        }
        try {
            $stored = [];
            foreach (DB::table('platform_settings')->get() as $r) {
                $v = json_decode($r->value, true) ?: [];
                foreach (self::SECRETS[$r->section]['fields'] ?? [] as $f) {
                    if (is_string($v[$f] ?? null) && $v[$f] !== '') {
                        try {
                            $v[$f] = Crypto::decrypt($v[$f]);
                        } catch (Throwable) {
                            $v[$f] = '';
                        }
                    }
                }
                $stored[$r->section] = $v;
            }
            $base = self::defaults();
            $value = [];
            foreach (self::SECTIONS as $s) {
                $value[$s] = self::merge($base[$s], $stored[$s] ?? null);
            }
            self::$stored = $stored;
            self::$at = microtime(true);

            return self::$cache = $value;
        } catch (Throwable) {
            return self::$cache ?? self::defaults();
        }
    }

    /** Plan limits; -1 = unlimited. */
    public static function planLimits(string $plan): array
    {
        return self::get()['pricing']['plans'][$plan]['limits'];
    }

    public static function planPrice(string $plan): int|float
    {
        return self::get()['pricing']['plans'][$plan]['price'];
    }

    /** Super Admin view: resolved values, which fields are overridden, secrets masked. */
    public static function describe(): array
    {
        self::invalidate();
        $value = self::get();
        $base = self::defaults();
        $out = [];
        foreach (self::SECTIONS as $s) {
            $v = $value[$s];
            $secrets = [];
            foreach (self::SECRETS[$s]['fields'] ?? [] as $f) {
                $val = (string) ($v[$f] ?? '');
                $fromAdmin = ! empty(self::$stored[$s][$f]);
                $secrets[$f] = ['set' => $val !== '', 'masked' => $val !== '' ? Crypto::mask($val) : null, 'source' => $fromAdmin ? 'admin' : (! empty($base[$s][$f]) ? 'env' : 'none')];
                unset($v[$f]);
            }
            $out[$s] = ['values' => (object) $v, 'secrets' => (object) $secrets, 'overridden' => array_keys(self::$stored[$s] ?? [])];
        }

        return $out;
    }

    /** Plain fields replace the override, null removes it; secrets: absent keeps, ''/null clears, else encrypted. */
    public static function saveSection(string $section, array $patch, string $adminId): array
    {
        $row = DB::table('platform_settings')->where('section', $section)->first();
        $current = $row ? (json_decode($row->value, true) ?: []) : [];
        $spec = self::SECRETS[$section] ?? null;
        foreach ($patch as $k => $v) {
            if ($spec && in_array($k, $spec['fields'], true)) {
                if ($v === '' || $v === null) {
                    unset($current[$k]);
                } else {
                    $current[$k] = Crypto::encrypt((string) $v, $spec['purpose']);
                }

                continue;
            }
            if ($v === null) {
                unset($current[$k]);
            } else {
                $current[$k] = $v;
            }
        }
        DB::table('platform_settings')->upsert(
            [['section' => $section, 'value' => json_encode((object) $current), 'updated_by' => $adminId, 'updated_at' => now()]],
            ['section'], ['value', 'updated_by', 'updated_at'],
        );
        self::invalidate();

        return self::get();
    }

    /** Public, non-secret subset for the web client. */
    public static function public(): array
    {
        $p = self::get();

        return [
            'productName' => $p['branding']['productName'], 'tagline' => $p['branding']['tagline'], 'supportEmail' => $p['branding']['supportEmail'],
            'currency' => $p['pricing']['currency'], 'prices' => self::perPlan(fn ($pl) => $p['pricing']['plans'][$pl]['price']),
            'limits' => self::perPlan(fn ($pl) => $p['pricing']['plans'][$pl]['limits']), 'extraDeploymentMonthly' => $p['pricing']['addOns']['extraDeploymentMonthly'],
        ];
    }
}
