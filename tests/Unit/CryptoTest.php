<?php

namespace Tests\Unit;

use App\Support\Crypto;
use App\Support\Jwt;
use Tests\TestCase;

class CryptoTest extends TestCase
{
    public function test_round_trips_every_purpose_with_its_prefix(): void
    {
        config(['camplo.encryption_key' => str_repeat('ab', 32)]);
        Crypto::reset();
        foreach (Crypto::PURPOSES as $p) {
            $c = Crypto::encrypt('sk-secret', $p);
            $this->assertStringStartsWith("{$p}.", $c);
            $this->assertSame('sk-secret', Crypto::decrypt($c));
        }
    }

    /** Ciphertext produced by the Node app (ENCRYPTION_KEY = "ab" × 32) must decrypt here. */
    public function test_decrypts_node_ciphertext(): void
    {
        config(['camplo.encryption_key' => str_repeat('ab', 32)]);
        Crypto::reset();
        $node = trim((string) @file_get_contents(base_path('tests/fixtures/node-ciphertexts.json')));
        $this->assertNotSame('', $node, 'fixture missing');
        foreach (json_decode($node, true) as $c) {
            $this->assertSame($c['plain'], Crypto::decrypt($c['blob']));
        }
    }

    public function test_purposes_use_distinct_keys(): void
    {
        config(['camplo.encryption_key' => str_repeat('ab', 32)]);
        Crypto::reset();
        $c = Crypto::encrypt('x', 'ai');
        $this->expectException(\RuntimeException::class);
        Crypto::decrypt('webhook.'.substr($c, 3));
    }

    public function test_rejects_bad_master_key(): void
    {
        config(['camplo.encryption_key' => 'too-short']);
        Crypto::reset();
        $this->expectExceptionMessage('ENCRYPTION_KEY must decode to 32 bytes');
        Crypto::encrypt('x');
    }

    public function test_mask_shows_last_four(): void
    {
        $this->assertSame(str_repeat('•', 12).'1234', Crypto::mask('sk-abcdef1234'));
        $this->assertNull(Crypto::mask(''));
    }

    public function test_jwt_round_trip_and_tamper(): void
    {
        $t = Jwt::sign('11111111-1111-1111-1111-111111111111', '22222222-2222-2222-2222-222222222222', 'owner');
        $this->assertSame('owner', Jwt::verify($t)['role']);
        $parts = explode('.', $t);
        $this->assertNull(Jwt::verify($parts[0].'.'.$parts[1].'.AAAA'));
    }

    public function test_verifies_bcryptjs_hash(): void
    {
        $f = json_decode((string) file_get_contents(base_path('tests/fixtures/node-jwt.json')), true);
        $this->assertTrue(\App\Services\AuthService::checkPassword('camplo-demo', $f['bcrypt']));
        $this->assertFalse(\App\Services\AuthService::checkPassword('wrong', $f['bcrypt']));
    }

    /** A token minted by the Node app (jose, same secret) verifies here. */
    public function test_verifies_node_jwt(): void
    {
        $f = json_decode((string) file_get_contents(base_path('tests/fixtures/node-jwt.json')), true);
        config(['camplo.jwt_secret' => $f['secret']]);
        $iat = json_decode(base64_decode(strtr(explode('.', $f['token'])[1], '-_', '+/')), true)['iat'];
        $c = Jwt::verify($f['token'], $iat + 60);
        $this->assertSame($f['sub'], $c['sub']);
        $this->assertSame($f['tid'], $c['tid']);
    }
}
