<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Tests;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Kinetis\AuthJwt\JwtIssuer;
use PHPUnit\Framework\TestCase;

final class JwtIssuerTest extends TestCase
{
    private const string SECRET = 'test-secret-key-do-not-use-in-production';

    public function test_issues_a_token_with_the_subject_as_a_string_claim(): void
    {
        $token = new JwtIssuer(self::SECRET)->issue(42);

        $claims = JWT::decode($token, new Key(self::SECRET, 'HS256'));

        self::assertSame('42', $claims->sub);
    }

    public function test_extra_claims_are_included(): void
    {
        $token = new JwtIssuer(self::SECRET)->issue('user-42', ['role' => 'admin']);

        $claims = JWT::decode($token, new Key(self::SECRET, 'HS256'));

        self::assertSame('admin', $claims->role);
    }

    public function test_an_extra_claim_named_sub_cannot_override_the_real_subject(): void
    {
        $token = new JwtIssuer(self::SECRET)->issue('user-42', ['sub' => 'someone-else']);

        $claims = JWT::decode($token, new Key(self::SECRET, 'HS256'));

        self::assertSame('user-42', $claims->sub);
    }

    public function test_sets_an_expiry_claim_by_default(): void
    {
        $token = new JwtIssuer(self::SECRET)->issue('user-42', ttlSeconds: 60);

        $claims = JWT::decode($token, new Key(self::SECRET, 'HS256'));

        self::assertGreaterThan((int) $claims->iat, $claims->exp);
    }

    public function test_a_null_ttl_omits_the_expiry_claim(): void
    {
        $token = new JwtIssuer(self::SECRET)->issue('user-42', ttlSeconds: null);

        $claims = JWT::decode($token, new Key(self::SECRET, 'HS256'));

        self::assertFalse(property_exists($claims, 'exp'));
    }

    public function test_a_token_it_issues_is_verifiable_by_jwt_auth_middleware(): void
    {
        $token = new JwtIssuer(self::SECRET)->issue('user-42');

        // JWT::decode() throwing nothing is itself the assertion that the
        // signature verifies against the same secret/algorithm
        // JwtAuthMiddleware uses.
        $claims = JWT::decode($token, new Key(self::SECRET, 'HS256'));

        self::assertSame('user-42', $claims->sub);
    }

    public function test_every_issued_token_includes_a_jti_claim(): void
    {
        $token = new JwtIssuer(self::SECRET)->issue('user-42');

        $claims = JWT::decode($token, new Key(self::SECRET, 'HS256'));

        self::assertIsString($claims->jti);
        self::assertNotSame('', $claims->jti);
    }

    public function test_two_issued_tokens_have_different_jti_claims(): void
    {
        $issuer = new JwtIssuer(self::SECRET);

        $first = JWT::decode($issuer->issue('user-42'), new Key(self::SECRET, 'HS256'));
        $second = JWT::decode($issuer->issue('user-42'), new Key(self::SECRET, 'HS256'));

        self::assertNotSame($first->jti, $second->jti);
    }

    public function test_an_extra_claim_named_jti_cannot_override_the_real_one(): void
    {
        $token = new JwtIssuer(self::SECRET)->issue('user-42', ['jti' => 'attacker-supplied']);

        $claims = JWT::decode($token, new Key(self::SECRET, 'HS256'));

        self::assertNotSame('attacker-supplied', $claims->jti);
    }
}
