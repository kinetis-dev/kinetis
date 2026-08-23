<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Tests;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Kinetis\AuthJwt\Exception\JwkSetException;
use Kinetis\AuthJwt\JwkSet;
use Kinetis\AuthJwt\Tests\Fixtures\RsaKeyPair;
use PHPUnit\Framework\TestCase;

final class JwkSetTest extends TestCase
{
    public function test_builds_a_keys_array_with_one_entry_per_given_kid(): void
    {
        $set = JwkSet::fromRsaPublicKeys(['key-2026' => RsaKeyPair::PUBLIC_KEY]);

        self::assertCount(1, $set['keys']);
        self::assertSame('key-2026', $set['keys'][0]['kid']);
        self::assertSame('RSA', $set['keys'][0]['kty']);
        self::assertSame('sig', $set['keys'][0]['use']);
        self::assertSame('RS256', $set['keys'][0]['alg']);
    }

    public function test_respects_a_given_algorithm(): void
    {
        $set = JwkSet::fromRsaPublicKeys(['key-2026' => RsaKeyPair::PUBLIC_KEY], algorithm: 'RS384');

        self::assertSame('RS384', $set['keys'][0]['alg']);
    }

    public function test_multiple_keys_produce_multiple_entries_in_order(): void
    {
        $set = JwkSet::fromRsaPublicKeys([
            'old' => RsaKeyPair::PUBLIC_KEY,
            'new' => RsaKeyPair::PUBLIC_KEY,
        ]);

        self::assertSame(['old', 'new'], array_column($set['keys'], 'kid'));
    }

    public function test_an_invalid_pem_throws_a_named_exception(): void
    {
        $this->expectException(JwkSetException::class);

        JwkSet::fromRsaPublicKeys(['key-2026' => 'not a real pem key']);
    }

    public function test_the_produced_jwk_is_a_real_usable_key_a_token_verifies_against(): void
    {
        // The actual, real-world point of this class: a client fetching
        // this JWK set and feeding it to a standard JWK-consuming
        // library (firebase/php-jwt's own JWK::parseKeySet(), used here)
        // gets back a key that genuinely verifies a token signed with
        // the matching private key.
        $set = JwkSet::fromRsaPublicKeys(['current' => RsaKeyPair::PUBLIC_KEY]);
        $keys = JWK::parseKeySet($set);

        $token = JWT::encode(['sub' => 'user-42', 'iat' => time()], RsaKeyPair::PRIVATE_KEY, 'RS256', 'current');
        $claims = JWT::decode($token, $keys);

        self::assertSame('user-42', $claims->sub);
    }
}
