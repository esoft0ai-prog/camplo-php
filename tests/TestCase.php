<?php

namespace Tests;

use App\Services\Mailer;
use App\Services\Platform;
use App\Support\Crypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /** The schema defines Postgres enum types; drop them with the tables between runs. */
    protected bool $dropTypes = true;

    protected function setUp(): void
    {
        parent::setUp();
        Mailer::$outbox = [];
        Platform::invalidate();
        Crypto::reset();
        config(['camplo.auto_activate' => true]);
    }

    /** Sign up a workspace (auto-activated) and log in as its owner. Returns [token, body]. */
    protected function owner(string $email = 'owner@example.com', string $plan = 'growth'): array
    {
        $this->postJson('/api/auth/signup', ['name' => 'Olu Owner', 'email' => $email, 'password' => 'password123', 'workspaceName' => 'Acme', 'plan' => $plan])->assertOk();
        $r = $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password123'])->assertOk();

        return [$r->json('accessToken'), $r->json()];
    }

    protected function as(string $token): static
    {
        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    protected function tenantId(string $email = 'owner@example.com'): string
    {
        return DB::table('tenants')->where('owner_email', $email)->value('id');
    }

    /** Invite a teammate, accept, and log in. Returns their access token. */
    protected function teammate(string $ownerToken, string $email, string $role = 'member'): string
    {
        $inv = $this->as($ownerToken)->postJson('/api/team/invitations', ['email' => $email, 'role' => $role])->assertOk();
        $this->flushHeaders();
        $this->postJson('/api/team/invitations/accept', ['token' => $inv->json('token'), 'password' => 'password123', 'name' => 'Tee Mate'])->assertOk();

        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password123'])->assertOk()->json('accessToken');
    }

    protected function cookieValue(TestResponse $r, string $name): ?string
    {
        foreach ($r->headers->getCookies() as $c) {
            if ($c->getName() === $name) {
                return $c->getValue();
            }
        }

        return null;
    }
}
