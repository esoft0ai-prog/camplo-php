<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class Polar
{
    /** A Polar checkout link for the plan: the API when a token is configured, else a static checkout link. */
    public static function createCheckout(string $plan, string $email, string $tenantId): ?string
    {
        $b = Platform::get()['billing'];
        $product = $b['productIds'][$plan] ?? '';
        if ($b['polarAccessToken'] && $product) {
            $res = Http::withToken($b['polarAccessToken'])->timeout(10)->post(rtrim($b['polarApiUrl'], '/').'/v1/checkouts/', [
                'products' => [$product], 'customer_email' => $email,
                'metadata' => ['tenant_id' => $tenantId, 'plan' => $plan],
                'success_url' => config('camplo.app_url').'/#/pending',
            ]);
            if (! $res->successful()) {
                throw new RuntimeException("Polar checkout failed ({$res->status()})");
            }
            if ($url = $res->json('url')) {
                return $url;
            }
        }
        $url = $b['checkoutUrls'][$plan] ?? '';

        return $url ? $url.'?customer_email='.rawurlencode($email)."&metadata[tenant_id]={$tenantId}&metadata[plan]={$plan}" : null;
    }

    public static function planForProduct(?string $productId, ?string $metadataPlan, ?string $productName): ?string
    {
        $ids = Platform::get()['billing']['productIds'];
        foreach (['starter', 'growth', 'watchtower', 'agency'] as $p) {
            if ($productId && ($ids[$p] ?? '') === $productId) {
                return $p;
            }
        }
        $m = strtolower((string) $metadataPlan);
        if (in_array($m, ['starter', 'growth', 'watchtower', 'agency'], true)) {
            return $m;
        }
        $n = strtolower((string) $productName);
        foreach (['watchtower', 'growth', 'agency', 'starter'] as $p) {
            if (str_contains($n, $p)) {
                return $p;
            }
        }

        return null;
    }

    /** Switch an existing Polar subscription to another plan's product (Polar prorates and sends subscription.updated). */
    public static function changeSubscriptionPlan(string $subscriptionId, string $plan): bool
    {
        $b = Platform::get()['billing'];
        $product = $b['productIds'][$plan] ?? '';
        if (! $b['polarAccessToken'] || ! $product) {
            return false;
        }
        try {
            return Http::withToken($b['polarAccessToken'])->timeout(10)
                ->patch(rtrim($b['polarApiUrl'], '/').'/v1/subscriptions/'.rawurlencode($subscriptionId), ['product_id' => $product])->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /** Super Admin "test connection": a read-only call proving the token (and organization) are valid. */
    public static function test(): array
    {
        $b = Platform::get()['billing'];
        if (! $b['polarAccessToken']) {
            return ['ok' => false, 'error' => 'No Polar access token set.'];
        }
        try {
            $q = $b['polarOrganizationId'] ? ['organization_id' => $b['polarOrganizationId'], 'limit' => 1] : ['limit' => 1];
            $r = Http::withToken($b['polarAccessToken'])->timeout(10)->get(rtrim($b['polarApiUrl'], '/').'/v1/products/', $q);

            return $r->successful() ? ['ok' => true] : ['ok' => false, 'error' => "Polar answered {$r->status()}"];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
