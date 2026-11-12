<?php

namespace App\Services;

use App\Support\Crypto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Polar webhooks (Standard Webhooks): HMAC-SHA256 over "{webhook-id}.{webhook-timestamp}.{body}". */
final class Billing
{
    public static function verifyPolarSignature(string $raw, ?string $id, ?string $ts, ?string $sig): bool
    {
        $secret = Platform::get()['billing']['polarWebhookSecret'];
        if (! $secret || ! $id || ! $ts || ! $sig || abs(time() - (int) $ts) > 300) {
            return false;
        }
        $secret = preg_replace('/^(whsec_|polar_whs_)/', '', $secret);
        $key = preg_match('#^[A-Za-z0-9+/=]+$#', $secret) ? (base64_decode($secret, true) ?: $secret) : $secret;
        $expected = base64_encode(hash_hmac('sha256', "{$id}.{$ts}.{$raw}", $key, true));
        foreach (explode(' ', $sig) as $s) {
            if (Crypto::safeEqual(preg_replace('/^v1,/', '', $s), $expected)) {
                return true;
            }
        }

        return false;
    }

    public static function handlePolarEvent(array $evt): array
    {
        $d = $evt['data'] ?? [];
        $email = $d['customer']['email'] ?? $d['customer_email'] ?? $d['user']['email'] ?? null;
        $tenantId = $d['metadata']['tenant_id'] ?? $d['customer']['metadata']['tenant_id'] ?? null;
        $t = $tenantId && Str::isUuid($tenantId) ? DB::table('tenants')->where('id', $tenantId)->first()
            : ($email ? DB::table('tenants')->where('owner_email', strtolower($email))->first() : null);
        if (! $t) {
            return ['handled' => false];
        }
        $plan = Polar::planForProduct($d['product_id'] ?? $d['product']['id'] ?? null, $d['metadata']['plan'] ?? null, $d['product']['name'] ?? $d['subscription']['product']['name'] ?? null);
        switch ($evt['type'] ?? '') {
            case 'order.created':
            case 'checkout.created':
                DB::table('tenants')->where('id', $t->id)->update(['polar_order_id' => $d['id'] ?? $t->polar_order_id, 'polar_customer_id' => $d['customer_id'] ?? $d['customer']['id'] ?? $t->polar_customer_id]);
                if ($evt['type'] === 'order.created') {
                    Mailer::send(Mailer::paymentReceived($t->owner_email, isset($d['amount']) ? '$'.number_format($d['amount'] / 100, 2, '.', '') : 'your plan fee'));
                }
                break;
            case 'subscription.created':
            case 'subscription.updated':
            case 'subscription.active':
                DB::table('tenants')->where('id', $t->id)->update(['polar_subscription_id' => $d['id'] ?? $t->polar_subscription_id, ...($plan ? ['plan' => $plan] : [])]);
                break;
            case 'subscription.canceled':
            case 'subscription.cancelled':
                DB::table('admin_action_log')->insert(['admin_id' => 'polar', 'action' => 'cancel_scheduled', 'target_tenant_id' => $t->id, 'note' => $d['current_period_end'] ?? null]);
                break;
            case 'subscription.revoked':
                DB::table('tenants')->where('id', $t->id)->update(['status' => 'suspended', 'suspended_at' => now()]);
                DB::table('admin_action_log')->insert(['admin_id' => 'polar', 'action' => 'suspend', 'target_tenant_id' => $t->id, 'note' => 'Subscription revoked']);
                break;
        }

        return ['handled' => true];
    }
}
