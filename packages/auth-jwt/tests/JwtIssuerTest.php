<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Tests;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Kinetis\AuthJwt\Exception\JwtConfigurationException;
use Kinetis\AuthJwt\Exception\JwtIssuerException;
use Kinetis\AuthJwt\JwkSet;
use Kinetis\AuthJwt\JwtAuthMiddleware;
use Kinetis\AuthJwt\JwtIssuer;
use Kinetis\AuthJwt\JwtSigningKey;
use Kinetis\AuthJwt\JwtVerificationKeys;
use Kinetis\AuthJwt\PublishedRsaKey;
use Kinetis\AuthJwt\Tests\Fixtures\RsaKeyPair;
use Kinetis\Container\AppScope;
use Kinetis\Http\CallableRequestHandler;
use Kinetis\Http\CurrentUserInterface;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JwtIssuerTest extends TestCase
{
    private const string SECRET = 'test-secret-key-do-not-use-in-production';

    // Long enough (85 bytes) to satisfy HS256/HS384/HS512's minimum
    // alike — self::SECRET (40 bytes) only clears HS256's.
    private const string LONG_SECRET = 'this-is-a-generously-long-test-secret-key-well-over-64-bytes-do-not-use-in-production';

    private static function signingKey(): JwtSigningKey
    {
        return JwtSigningKey::hmacSecret(self::SECRET);
    }

    /**
     * An integer application id becomes the canonical subject string at
     * issuance — the same conversion RefreshTokenStore::issue() makes,
     * so both token kinds name one subject.
     */
    public function test_issues_a_token_with_the_subject_as_a_string_claim(): void
    {
        $token = new JwtIssuer(self::signingKey())->issue(42);

        $claims = JWT::decode($token, new Key(self::SECRET, 'HS256'));

        self::assertSame('42', $claims->sub);
    }

    public function test_issue_rejects_an_empty_subject(): void
    {
        $this->expectException(JwtIssuerException::class);

        new JwtIssuer(self::signingKey())->issue('');
    }

    public function test_extra_claims_are_included(): void
    {
        $token = new JwtIssuer(self::signingKey())->issue('user-42', ['role' => 'admin']);

        $claims = JWT::decode($token, new Key(self::SECRET, 'HS256'));

        self::assertSame('admin', $claims->role);
    }

    public function test_an_extra_claim_named_sub_cannot_override_the_real_subject(): void
    {
        $token = new JwtIssuer(self::signingKey())->issue('user-42', ['sub' => 'someone-else']);

        $claims = JWT::decode($token, new Key(self::SECRET, 'HS256'));

        self::assertSame('user-42', $claims->sub);
    }

    public function test_sets_an_expiry_claim_by_default(): void
    {
        $token = new JwtIssuer(self::signingKey())->issue('user-42', ttlSeconds: 60);

        $claims = JWT::decode($token, new Key(self::SECRET, 'HS256'));

        self::assertGreaterThan((int) $claims->iat, $claims->exp);
    }

    public function test_a_null_ttl_omits_the_expiry_claim(): void
    {
        $token = new JwtIssuer(self::signingKey())->issue('user-42', ttlSeconds: null);

        $claims = JWT::decode($token, new Key(self::SECRET, 'HS256'));

        self::assertFalse(property_exists($claims, 'exp'));
    }

    public function test_a_zero_ttl_throws(): void
    {
        $this->expectException(JwtIssuerException::class);

        new JwtIssuer(self::signingKey())->issue('user-42', ttlSeconds: 0);
    }

    public function test_a_negative_ttl_throws(): void
    {
        $this->expectException(JwtIssuerException::class);

        new JwtIssuer(self::signingKey())->issue('user-42', ttlSeconds: -1);
    }

    public function test_a_ttl_large_enough_to_overflow_the_expiry_computation_throws(): void
    {
        $this->expectException(JwtIssuerException::class);

        new JwtIssuer(self::signingKey())->issue('user-42', ttlSeconds: PHP_INT_MAX);
    }

    public function test_the_largest_safe_ttl_below_the_overflow_boundary_succeeds(): void
    {
        // A generous safety margin below the real boundary (PHP_INT_MAX
        // - time()) — the exact boundary is sensitive to which second
        // time() lands on inside issue() versus here; this only needs to
        // prove a very large, still-safe TTL genuinely works, not pin
        // the boundary to the exact second.
        $ttlSeconds = PHP_INT_MAX - time() - 10;

        $token = new JwtIssuer(self::signingKey())->issue('user-42', ttlSeconds: $ttlSeconds);

        $claims = JWT::decode($token, new Key(self::SECRET, 'HS256'));

        self::assertGreaterThan((int) $claims->iat, $claims->exp);
    }

    public function test_a_token_it_issues_is_verifiable_by_jwt_auth_middleware(): void
    {
        $token = new JwtIssuer(self::signingKey())->issue('user-42');

        // JWT::decode() throwing nothing is itself the assertion that the
        // signature verifies against the same secret/algorithm
        // JwtAuthMiddleware uses.
        $claims = JWT::decode($token, new Key(self::SECRET, 'HS256'));

        self::assertSame('user-42', $claims->sub);
    }

    public function test_every_issued_token_includes_a_jti_claim(): void
    {
        $token = new JwtIssuer(self::signingKey())->issue('user-42');

        $claims = JWT::decode($token, new Key(self::SECRET, 'HS256'));

        self::assertIsString($claims->jti);
        self::assertNotSame('', $claims->jti);
    }

    public function test_two_issued_tokens_have_different_jti_claims(): void
    {
        $issuer = new JwtIssuer(self::signingKey());

        $first = JWT::decode($issuer->issue('user-42'), new Key(self::SECRET, 'HS256'));
        $second = JWT::decode($issuer->issue('user-42'), new Key(self::SECRET, 'HS256'));

        self::assertNotSame($first->jti, $second->jti);
    }

    public function test_an_extra_claim_named_jti_cannot_override_the_real_one(): void
    {
        $token = new JwtIssuer(self::signingKey())->issue('user-42', ['jti' => 'attacker-supplied']);

        $claims = JWT::decode($token, new Key(self::SECRET, 'HS256'));

        self::assertNotSame('attacker-supplied', $claims->jti);
    }

    public function test_a_given_kid_is_written_into_the_token_header(): void
    {
        $token = new JwtIssuer(JwtSigningKey::hmacSecret(self::SECRET, kid: 'key-2026'))->issue('user-42');

        // JWT::decode() only writes back into $headers when it's already
        // non-null going in — a placeholder value, overwritten with the
        // real header on return.
        $headers = new \stdClass();
        JWT::decode($token, new Key(self::SECRET, 'HS256'), $headers);

        self::assertSame('key-2026', $headers->kid);
    }

    public function test_no_kid_by_default(): void
    {
        $token = new JwtIssuer(self::signingKey())->issue('user-42');

        $headers = new \stdClass();
        JWT::decode($token, new Key(self::SECRET, 'HS256'), $headers);

        self::assertFalse(property_exists($headers, 'kid'));
    }

    public function test_no_iss_or_aud_claim_by_default(): void
    {
        $token = new JwtIssuer(self::signingKey())->issue('user-42');

        $claims = JWT::decode($token, new Key(self::SECRET, 'HS256'));

        self::assertFalse(property_exists($claims, 'iss'));
        self::assertFalse(property_exists($claims, 'aud'));
    }

    public function test_a_configured_issuer_is_written_into_every_token(): void
    {
        $token = new JwtIssuer(self::signingKey(), issuer: 'my-app')->issue('user-42');

        $claims = JWT::decode($token, new Key(self::SECRET, 'HS256'));

        self::assertSame('my-app', $claims->iss);
    }

    public function test_a_string_audience_is_written_into_every_token(): void
    {
        $token = new JwtIssuer(self::signingKey(), audience: 'my-app-api')->issue('user-42');

        $claims = JWT::decode($token, new Key(self::SECRET, 'HS256'));

        self::assertSame('my-app-api', $claims->aud);
    }

    public function test_a_list_audience_is_written_into_every_token(): void
    {
        $token = new JwtIssuer(self::signingKey(), audience: ['svc-a', 'svc-b'])->issue('user-42');

        $claims = JWT::decode($token, new Key(self::SECRET, 'HS256'));

        self::assertSame(['svc-a', 'svc-b'], $claims->aud);
    }

    public function test_an_extra_claim_named_iss_cannot_override_the_configured_one(): void
    {
        $issuer = new JwtIssuer(self::signingKey(), issuer: 'my-app');
        $token = $issuer->issue('user-42', ['iss' => 'attacker-supplied']);

        $claims = JWT::decode($token, new Key(self::SECRET, 'HS256'));

        self::assertSame('my-app', $claims->iss);
    }

    public function test_an_extra_claim_named_aud_cannot_override_the_configured_one(): void
    {
        $issuer = new JwtIssuer(self::signingKey(), audience: 'my-app-api');
        $token = $issuer->issue('user-42', ['aud' => 'attacker-supplied']);

        $claims = JWT::decode($token, new Key(self::SECRET, 'HS256'));

        self::assertSame('my-app-api', $claims->aud);
    }

    public function test_construction_throws_when_issuer_is_an_empty_string(): void
    {
        $this->expectException(JwtConfigurationException::class);

        new JwtIssuer(self::signingKey(), issuer: '');
    }

    public function test_construction_throws_when_audience_is_an_empty_string(): void
    {
        $this->expectException(JwtConfigurationException::class);

        new JwtIssuer(self::signingKey(), audience: '');
    }

    public function test_construction_throws_when_audience_is_an_empty_array(): void
    {
        $this->expectException(JwtConfigurationException::class);

        new JwtIssuer(self::signingKey(), audience: []);
    }

    public function test_construction_throws_when_audience_array_contains_a_non_string(): void
    {
        $this->expectException(JwtConfigurationException::class);

        new JwtIssuer(self::signingKey(), audience: ['svc-a', 123]);
    }

    public function test_construction_throws_when_audience_array_contains_an_empty_string(): void
    {
        $this->expectException(JwtConfigurationException::class);

        new JwtIssuer(self::signingKey(), audience: ['svc-a', '']);
    }

    /**
     * An associative array serializes via json_encode() as a JSON
     * object, not the JWT standard's array-of-strings "aud" form — a
     * token issued this way would fail every verifier's audience check,
     * including one configured with an exactly matching value, so this
     * must be rejected at construction rather than silently accepted.
     */
    public function test_construction_throws_when_audience_is_an_associative_array(): void
    {
        $this->expectException(JwtConfigurationException::class);

        new JwtIssuer(self::signingKey(), audience: ['primary' => 'svc-a']);
    }

    /**
     * A sparse numeric array (a gap in the keys) is not a list either —
     * array_is_list() is false for it the same as for an associative
     * array, and json_encode() would still serialize it as a JSON
     * object rather than an array.
     */
    public function test_construction_throws_when_audience_is_a_sparse_numeric_array(): void
    {
        $this->expectException(JwtConfigurationException::class);

        new JwtIssuer(self::signingKey(), audience: [0 => 'svc-a', 2 => 'svc-b']);
    }







    /**
     * @return iterable<string, array{string}>
     */
    public static function hmacAlgorithms(): iterable
    {
        yield 'HS256' => ['HS256'];
        yield 'HS384' => ['HS384'];
        yield 'HS512' => ['HS512'];
    }

    #[DataProvider('hmacAlgorithms')]
    public function test_issues_a_verifiable_token_under_every_hmac_algorithm(string $algorithm): void
    {
        $token = new JwtIssuer(JwtSigningKey::hmacSecret(self::LONG_SECRET, $algorithm))->issue('user-42');

        $claims = JWT::decode($token, new Key(self::LONG_SECRET, $algorithm));

        self::assertSame('user-42', $claims->sub);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rsaAlgorithms(): iterable
    {
        yield 'RS256' => ['RS256'];
        yield 'RS384' => ['RS384'];
        yield 'RS512' => ['RS512'];
    }

    #[DataProvider('rsaAlgorithms')]
    public function test_issues_a_verifiable_token_under_every_rsa_algorithm(string $algorithm): void
    {
        $token = new JwtIssuer(JwtSigningKey::rsaPrivateKey(RsaKeyPair::PRIVATE_KEY, $algorithm))->issue('user-42');

        $claims = JWT::decode($token, new Key(RsaKeyPair::PUBLIC_KEY, $algorithm));

        self::assertSame('user-42', $claims->sub);
    }




    /**
     * The same invariant through a published-and-reparsed JWKS: the kid
     * this issuer stamps must match one JwkSet can publish and a JWKS
     * parser can read back.
     */
    public function test_a_token_issued_with_a_real_kid_verifies_through_a_matching_jwks(): void
    {
        $set = JwkSet::fromRsaPublicKeys([new PublishedRsaKey('current', RsaKeyPair::PUBLIC_KEY)]);
        $keys = JWK::parseKeySet($set);

        $token = new JwtIssuer(JwtSigningKey::rsaPrivateKey(RsaKeyPair::PRIVATE_KEY, kid: 'current'))->issue('user-42');
        $claims = JWT::decode($token, $keys);

        self::assertSame('user-42', $claims->sub);
    }
}
