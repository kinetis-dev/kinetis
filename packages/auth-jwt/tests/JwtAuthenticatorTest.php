<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Tests;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Kinetis\AuthJwt\Exception\JwtConfigurationException;
use Kinetis\AuthJwt\JoseHeader;
use Kinetis\AuthJwt\JwkSet;
use Kinetis\AuthJwt\JwtAuthenticator;
use Kinetis\AuthJwt\JwtIssuer;
use Kinetis\AuthJwt\JwtKeyValidator;
use Kinetis\AuthJwt\JwtSigningKey;
use Kinetis\AuthJwt\JwtUser;
use Kinetis\AuthJwt\JwtVerificationKeys;
use Kinetis\AuthJwt\PublishedRsaKey;
use Kinetis\AuthJwt\RevocationStore;
use Kinetis\AuthJwt\Tests\Fixtures\InMemorySimpleCache;
use Kinetis\AuthJwt\Tests\Fixtures\RecordingSimpleCache;
use Kinetis\AuthJwt\Tests\Fixtures\RsaKeyPair;
use Kinetis\AuthJwt\Tests\Fixtures\SecondRsaKeyPair;
use Kinetis\AuthJwt\Tests\Fixtures\ThrowingSimpleCache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The whole authentication decision, with no HTTP anywhere in it: a
 * token in, a JwtUser or null out. Every cryptographic, claim and
 * revocation rule this package enforces is proven here; JwtAuthMiddleware's
 * own suite proves only the transport around it.
 */
final class JwtAuthenticatorTest extends TestCase
{
    private const string SECRET = 'test-secret-key-do-not-use-in-production';

    // Long enough (85 bytes) to satisfy HS256/HS384/HS512's minimum
    // alike — self::SECRET (40 bytes) only clears HS256's.
    private const string LONG_SECRET = 'this-is-a-generously-long-test-secret-key-well-over-64-bytes-do-not-use-in-production';

    /**
     * The HS256 configuration these tests default to: two views of
     * self::SECRET, one for verification and one for signing.
     */
    private static function keys(): JwtVerificationKeys
    {
        return JwtVerificationKeys::hmacSecret(self::SECRET);
    }

    private static function signingKey(): JwtSigningKey
    {
        return JwtSigningKey::hmacSecret(self::SECRET);
    }

    public function test_a_valid_token_authenticates_its_subject(): void
    {
        $authenticator = new JwtAuthenticator(self::keys());
        $token = new JwtIssuer(self::signingKey())->issue('user-42');

        $user = $authenticator->authenticate($token);

        self::assertInstanceOf(JwtUser::class, $user);
        self::assertSame('user-42', $user->id());
    }

    public function test_a_malformed_token_is_rejected(): void
    {
        self::assertNull(new JwtAuthenticator(self::keys())->authenticate('not-a-jwt-at-all'));
    }

    public function test_an_empty_token_is_rejected(): void
    {
        self::assertNull(new JwtAuthenticator(self::keys())->authenticate(''));
    }

    public function test_a_token_signed_with_a_different_key_is_rejected(): void
    {
        $otherKey = JwtSigningKey::hmacSecret('a-completely-different-secret-key-of-sufficient-length');
        $token = new JwtIssuer($otherKey)->issue('user-42');

        self::assertNull(new JwtAuthenticator(self::keys())->authenticate($token));
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $token = JWT::encode(
            ['sub' => 'user-42', 'iat' => time() - 3600, 'exp' => time() - 1800],
            self::SECRET,
            'HS256',
        );

        self::assertNull(new JwtAuthenticator(self::keys())->authenticate($token));
    }

    public function test_a_token_with_no_subject_claim_is_rejected(): void
    {
        $token = JWT::encode(['iat' => time()], self::SECRET, 'HS256');

        self::assertNull(new JwtAuthenticator(self::keys())->authenticate($token));
    }

    /**
     * A subject is one canonical non-empty string across this package —
     * the form JwtIssuer writes and RefreshTokenStore stores. A token
     * whose `sub` is a JSON number or an empty string names no user this
     * package can act on, so it never authenticates at all.
     */
    #[DataProvider('nonCanonicalSubjectClaims')]
    public function test_a_token_whose_subject_is_not_a_non_empty_string_is_rejected(mixed $sub): void
    {
        $token = JWT::encode(['sub' => $sub, 'iat' => time()], self::SECRET, 'HS256');

        self::assertNull(new JwtAuthenticator(self::keys())->authenticate($token));
    }

    public static function nonCanonicalSubjectClaims(): iterable
    {
        yield 'an integer' => [42];
        yield 'an empty string' => [''];
        yield 'a float' => [42.5];
        yield 'a boolean' => [true];
        yield 'a list' => [['user-42']];
    }

    public function test_a_revoked_token_is_rejected(): void
    {
        $revocationStore = new RevocationStore(new InMemorySimpleCache());
        $authenticator = new JwtAuthenticator(self::keys(), revocationStore: $revocationStore);
        $token = new JwtIssuer(self::signingKey())->issue('user-42');
        $claims = JWT::decode($token, new Key(self::SECRET, 'HS256'));
        $revocationStore->revoke($claims->jti, 60);

        self::assertNull($authenticator->authenticate($token));
    }

    public function test_a_non_revoked_token_still_authenticates_when_a_revocation_store_is_configured(): void
    {
        $revocationStore = new RevocationStore(new InMemorySimpleCache());
        $authenticator = new JwtAuthenticator(self::keys(), revocationStore: $revocationStore);
        $token = new JwtIssuer(self::signingKey())->issue('user-42');

        self::assertNotNull($authenticator->authenticate($token));
    }

    public function test_revoking_one_token_does_not_reject_a_different_one(): void
    {
        $revocationStore = new RevocationStore(new InMemorySimpleCache());
        $authenticator = new JwtAuthenticator(self::keys(), revocationStore: $revocationStore);

        $revoked = new JwtIssuer(self::signingKey())->issue('user-42');
        $claims = JWT::decode($revoked, new Key(self::SECRET, 'HS256'));
        $revocationStore->revoke($claims->jti, 60);

        $stillValid = new JwtIssuer(self::signingKey())->issue('user-42');

        self::assertNotNull($authenticator->authenticate($stillValid));
    }

    /**
     * A revocation lookup that cannot answer is not an invalid
     * credential: the failure propagates rather than being relabelled
     * as one more null, since a store that is down has said nothing
     * about this token at all.
     */
    public function test_a_throwing_revocation_lookup_propagates(): void
    {
        $authenticator = new JwtAuthenticator(
            self::keys(),
            revocationStore: new RevocationStore(new ThrowingSimpleCache()),
        );
        $token = new JwtIssuer(self::signingKey())->issue('user-42');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(ThrowingSimpleCache::MESSAGE);

        $authenticator->authenticate($token);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedJtiClaims(): iterable
    {
        yield 'missing jti' => [null]; // null here means "omit the claim entirely" — see the test body.
        yield 'empty jti' => [''];
        yield 'non-string jti' => [12345];
    }

    /**
     * A revocation store is configured but the token carries no jti the
     * denylist could ever name — rejected outright rather than
     * authenticated with the one check it makes silently skipped. Every
     * case here is a real, validly-signed token: the rejection is this
     * class's own, not a signature or decode failure.
     */
    #[DataProvider('malformedJtiClaims')]
    public function test_a_token_with_a_malformed_jti_is_rejected_when_a_revocation_store_is_configured(
        mixed $jti,
    ): void {
        $revocationStore = new RevocationStore(new InMemorySimpleCache());
        $authenticator = new JwtAuthenticator(self::keys(), revocationStore: $revocationStore);

        $claims = ['sub' => 'user-42'];

        if ($jti !== null) {
            $claims['jti'] = $jti;
        }

        self::assertNull($authenticator->authenticate(JWT::encode($claims, self::SECRET, 'HS256')));
    }

    /**
     * The jti gate must reject a malformed token before the revocation
     * lookup ever runs — proven against a cache that records every get()
     * call, not just inferred from the null.
     */
    public function test_a_malformed_jti_never_reaches_the_revocation_lookup(): void
    {
        $cache = new RecordingSimpleCache();
        $authenticator = new JwtAuthenticator(self::keys(), revocationStore: new RevocationStore($cache));
        $token = JWT::encode(['sub' => 'user-42', 'iat' => time()], self::SECRET, 'HS256');

        self::assertNull($authenticator->authenticate($token));
        self::assertSame([], $cache->getCalls);
    }

    public function test_a_valid_token_authenticates_when_no_revocation_store_is_configured(): void
    {
        // The default — revocationStore is optional and null by default,
        // matching every other test above that never mentions it.
        $token = new JwtIssuer(self::signingKey())->issue('user-42');

        self::assertNotNull(new JwtAuthenticator(self::keys())->authenticate($token));
    }

    public function test_rs256_verifies_a_token_signed_with_the_matching_private_key(): void
    {
        $authenticator = new JwtAuthenticator(JwtVerificationKeys::rsaPublicKey(RsaKeyPair::PUBLIC_KEY));
        $token = new JwtIssuer(JwtSigningKey::rsaPrivateKey(RsaKeyPair::PRIVATE_KEY))->issue('user-42');

        $user = $authenticator->authenticate($token);

        self::assertInstanceOf(JwtUser::class, $user);
        self::assertSame('user-42', $user->id());
    }

    public function test_rs256_rejects_a_token_that_was_never_signed_with_the_private_key(): void
    {
        $authenticator = new JwtAuthenticator(JwtVerificationKeys::rsaPublicKey(RsaKeyPair::PUBLIC_KEY));
        // Signed with an HS256 secret, not the RSA private key — the
        // authenticator only ever tries to verify as RS256 against the
        // configured public key, so this must fail, not silently
        // "succeed" under a different algorithm.
        $token = JWT::encode(['sub' => 'user-42', 'iat' => time()], self::SECRET, 'HS256');

        self::assertNull($authenticator->authenticate($token));
    }

    public function test_a_token_with_the_matching_issuer_authenticates(): void
    {
        $authenticator = new JwtAuthenticator(self::keys(), expectedIssuer: 'my-app');
        $token = new JwtIssuer(self::signingKey(), issuer: 'my-app')->issue('user-42');

        self::assertNotNull($authenticator->authenticate($token));
    }

    public function test_a_token_with_no_iss_is_rejected_when_an_issuer_is_expected(): void
    {
        $authenticator = new JwtAuthenticator(self::keys(), expectedIssuer: 'my-app');
        // No issuer configured on this JwtIssuer at all.
        $token = new JwtIssuer(self::signingKey())->issue('user-42');

        self::assertNull($authenticator->authenticate($token));
    }

    public function test_a_token_with_the_wrong_issuer_is_rejected(): void
    {
        $authenticator = new JwtAuthenticator(self::keys(), expectedIssuer: 'my-app');
        $token = new JwtIssuer(self::signingKey(), issuer: 'someone-elses-app')->issue('user-42');

        self::assertNull($authenticator->authenticate($token));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedIssuerValues(): iterable
    {
        yield 'empty string' => [''];
        yield 'integer' => [42];
        yield 'array' => [['my-app']];
        yield 'boolean' => [true];
    }

    #[DataProvider('malformedIssuerValues')]
    public function test_a_token_with_a_malformed_iss_is_rejected(mixed $iss): void
    {
        $authenticator = new JwtAuthenticator(self::keys(), expectedIssuer: 'my-app');
        $token = JWT::encode(['sub' => 'user-42', 'iat' => time(), 'iss' => $iss], self::SECRET, 'HS256');

        self::assertNull($authenticator->authenticate($token));
    }

    public function test_a_token_with_a_matching_string_audience_authenticates(): void
    {
        $authenticator = new JwtAuthenticator(self::keys(), acceptedAudiences: ['svc-a']);
        $token = new JwtIssuer(self::signingKey(), audience: 'svc-a')->issue('user-42');

        self::assertNotNull($authenticator->authenticate($token));
    }

    public function test_a_token_with_a_matching_list_audience_authenticates(): void
    {
        // Any-match semantics: only one of the token's own audiences
        // needs to be present in the accepted list.
        $authenticator = new JwtAuthenticator(self::keys(), acceptedAudiences: ['svc-b']);
        $token = new JwtIssuer(self::signingKey(), audience: ['svc-a', 'svc-b'])->issue('user-42');

        self::assertNotNull($authenticator->authenticate($token));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function rejectedAudienceClaims(): iterable
    {
        yield 'no matching audience' => ['svc-c'];
        yield 'an empty string audience' => [''];
        yield 'an empty audience list' => [[]];
        yield 'a mixed-type audience list' => [['svc-a', 123]];
        // Reachable only from a hand-crafted or third-party token, since
        // JwtIssuer itself refuses to construct an associative
        // $audience: JWT::encode()'s own json_encode() serializes a PHP
        // associative array as a JSON object, e.g. {"primary":"svc-a"}.
        yield 'a JSON object audience' => [['primary' => 'svc-a']];
    }

    #[DataProvider('rejectedAudienceClaims')]
    public function test_a_token_whose_audience_names_nothing_accepted_is_rejected(mixed $aud): void
    {
        $authenticator = new JwtAuthenticator(self::keys(), acceptedAudiences: ['svc-a']);
        $token = JWT::encode(['sub' => 'user-42', 'iat' => time(), 'aud' => $aud], self::SECRET, 'HS256');

        self::assertNull($authenticator->authenticate($token));
    }

    public function test_a_token_with_no_aud_is_rejected_when_audiences_are_expected(): void
    {
        $authenticator = new JwtAuthenticator(self::keys(), acceptedAudiences: ['svc-a']);
        // No audience configured on this JwtIssuer at all.
        $token = new JwtIssuer(self::signingKey())->issue('user-42');

        self::assertNull($authenticator->authenticate($token));
    }

    public function test_both_issuer_and_audience_matching_authenticates(): void
    {
        $authenticator = new JwtAuthenticator(
            self::keys(),
            expectedIssuer: 'my-app',
            acceptedAudiences: ['svc-a'],
        );
        $token = new JwtIssuer(self::signingKey(), issuer: 'my-app', audience: 'svc-a')->issue('user-42');

        self::assertNotNull($authenticator->authenticate($token));
    }

    public function test_matching_issuer_alone_is_not_enough_when_audience_is_also_required(): void
    {
        $authenticator = new JwtAuthenticator(
            self::keys(),
            expectedIssuer: 'my-app',
            acceptedAudiences: ['svc-a'],
        );
        $token = new JwtIssuer(self::signingKey(), issuer: 'my-app', audience: 'svc-wrong')->issue('user-42');

        self::assertNull($authenticator->authenticate($token));
    }

    public function test_matching_audience_alone_is_not_enough_when_issuer_is_also_required(): void
    {
        $authenticator = new JwtAuthenticator(
            self::keys(),
            expectedIssuer: 'my-app',
            acceptedAudiences: ['svc-a'],
        );
        $token = new JwtIssuer(self::signingKey(), issuer: 'someone-else', audience: 'svc-a')->issue('user-42');

        self::assertNull($authenticator->authenticate($token));
    }

    public function test_no_issuer_or_audience_constraint_by_default_accepts_a_token_carrying_either_claim(): void
    {
        $authenticator = new JwtAuthenticator(self::keys());
        $token = new JwtIssuer(self::signingKey(), issuer: 'my-app', audience: 'svc-a')->issue('user-42');

        self::assertNotNull($authenticator->authenticate($token));
    }

    /**
     * A rejected issuer must be exactly as inert as any other failure:
     * the revocation cache is never consulted for a token that already
     * failed an earlier gate.
     */
    public function test_an_issuer_mismatch_never_reaches_revocation(): void
    {
        $cache = new RecordingSimpleCache();
        $authenticator = new JwtAuthenticator(
            self::keys(),
            revocationStore: new RevocationStore($cache),
            expectedIssuer: 'my-app',
        );
        $token = new JwtIssuer(self::signingKey(), issuer: 'someone-else')->issue('user-42');

        self::assertNull($authenticator->authenticate($token));
        self::assertSame([], $cache->getCalls);
    }

    /**
     * Key rotation succeeding (a token validly signed under a recognized
     * kid) must not bypass the issuer check — the two are independent
     * gates.
     */
    public function test_a_key_set_still_enforces_issuer_after_verifying_under_the_matching_kid(): void
    {
        $authenticator = new JwtAuthenticator(
            self::publishedKeySet([new PublishedRsaKey('current', RsaKeyPair::PUBLIC_KEY)]),
            expectedIssuer: 'my-app',
        );
        $signingKey = JwtSigningKey::rsaPrivateKey(RsaKeyPair::PRIVATE_KEY, kid: 'current');
        $token = new JwtIssuer($signingKey, issuer: 'someone-else')->issue('user-42');

        self::assertNull($authenticator->authenticate($token));
    }

    public function test_a_key_set_authenticates_when_kid_and_issuer_both_match(): void
    {
        $authenticator = new JwtAuthenticator(
            self::publishedKeySet([new PublishedRsaKey('current', RsaKeyPair::PUBLIC_KEY)]),
            expectedIssuer: 'my-app',
        );
        $signingKey = JwtSigningKey::rsaPrivateKey(RsaKeyPair::PRIVATE_KEY, kid: 'current');
        $token = new JwtIssuer($signingKey, issuer: 'my-app')->issue('user-42');

        $user = $authenticator->authenticate($token);

        self::assertInstanceOf(JwtUser::class, $user);
        self::assertSame('user-42', $user->id());
    }

    public function test_construction_throws_when_expected_issuer_is_an_empty_string(): void
    {
        $this->expectException(JwtConfigurationException::class);

        new JwtAuthenticator(self::keys(), expectedIssuer: '');
    }

    public function test_construction_throws_when_accepted_audiences_is_an_empty_array(): void
    {
        $this->expectException(JwtConfigurationException::class);

        new JwtAuthenticator(self::keys(), acceptedAudiences: []);
    }

    public function test_construction_throws_when_accepted_audiences_contains_an_empty_string(): void
    {
        $this->expectException(JwtConfigurationException::class);

        new JwtAuthenticator(self::keys(), acceptedAudiences: ['svc-a', '']);
    }

    public function test_construction_throws_when_accepted_audiences_contains_a_non_string(): void
    {
        $this->expectException(JwtConfigurationException::class);

        new JwtAuthenticator(self::keys(), acceptedAudiences: ['svc-a', 123]);
    }

    /**
     * $acceptedAudiences is documented as list<string> — an associative
     * array doesn't match that shape, even though in_array()/
     * array_intersect() would happen to still work on it at runtime.
     */
    public function test_construction_throws_when_accepted_audiences_is_an_associative_array(): void
    {
        $this->expectException(JwtConfigurationException::class);

        new JwtAuthenticator(self::keys(), acceptedAudiences: ['primary' => 'svc-a']);
    }

    public function test_construction_throws_when_accepted_audiences_is_a_sparse_numeric_array(): void
    {
        $this->expectException(JwtConfigurationException::class);

        new JwtAuthenticator(self::keys(), acceptedAudiences: [0 => 'svc-a', 2 => 'svc-b']);
    }

    /**
     * @return iterable<string, array{string|array<int, string>, list<string>}>
     */
    public static function acceptedAudienceRoundTrips(): iterable
    {
        yield 'single string audience' => ['svc-a', ['svc-a']];
        yield 'single-element list audience' => [['svc-a'], ['svc-a']];
        yield 'multi-element list audience, first entry accepted' => [['svc-a', 'svc-b'], ['svc-a']];
        yield 'multi-element list audience, second entry accepted' => [['svc-a', 'svc-b'], ['svc-b']];
    }

    /**
     * Every audience shape JwtIssuer will actually construct and emit
     * must authenticate successfully against a verifier configured with
     * a matching accepted audience — a round-trip invariant across the
     * whole accepted shape space, not just one example of each.
     *
     * @param string|array<int, string> $issuedAudience
     * @param list<string> $acceptedAudiences
     */
    #[DataProvider('acceptedAudienceRoundTrips')]
    public function test_every_accepted_audience_shape_round_trips_successfully(
        string|array $issuedAudience,
        array $acceptedAudiences,
    ): void {
        $authenticator = new JwtAuthenticator(self::keys(), acceptedAudiences: $acceptedAudiences);
        $token = new JwtIssuer(self::signingKey(), audience: $issuedAudience)->issue('user-42');

        self::assertNotNull($authenticator->authenticate($token));
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
    public function test_a_real_token_authenticates_under_every_hmac_algorithm(string $algorithm): void
    {
        $authenticator = new JwtAuthenticator(JwtVerificationKeys::hmacSecret(self::LONG_SECRET, $algorithm));
        $token = new JwtIssuer(JwtSigningKey::hmacSecret(self::LONG_SECRET, $algorithm))->issue('user-42');

        $user = $authenticator->authenticate($token);

        self::assertInstanceOf(JwtUser::class, $user);
        self::assertSame('user-42', $user->id());
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
    public function test_a_real_token_authenticates_under_every_rsa_algorithm(string $algorithm): void
    {
        $authenticator = new JwtAuthenticator(JwtVerificationKeys::rsaPublicKey(RsaKeyPair::PUBLIC_KEY, $algorithm));
        $token = new JwtIssuer(JwtSigningKey::rsaPrivateKey(RsaKeyPair::PRIVATE_KEY, $algorithm))->issue('user-42');

        $user = $authenticator->authenticate($token);

        self::assertInstanceOf(JwtUser::class, $user);
        self::assertSame('user-42', $user->id());
    }

    /**
     * A JWK Set published by JwkSet, serialized the way a
     * `.well-known/jwks.json` route serializes it, and parsed back.
     *
     * @param list<PublishedRsaKey> $keys
     */
    private static function publishedKeySet(array $keys): JwtVerificationKeys
    {
        return JwtVerificationKeys::jwks((string) json_encode(JwkSet::fromRsaPublicKeys($keys), JSON_THROW_ON_ERROR));
    }

    /**
     * Two kids under two different key pairs, so which one a token
     * reaches is observable.
     */
    private static function keySetWithKids(string $firstKid, string $secondKid): JwtVerificationKeys
    {
        return self::publishedKeySet([
            new PublishedRsaKey($firstKid, RsaKeyPair::PUBLIC_KEY),
            new PublishedRsaKey($secondKid, SecondRsaKeyPair::publicKey()),
        ]);
    }

    /**
     * @param array<string, mixed> $header
     */
    private function tokenWithHeader(array $header): string
    {
        $segments = [
            JWT::urlsafeB64Encode((string) JWT::jsonEncode($header)),
            JWT::urlsafeB64Encode((string) JWT::jsonEncode(['sub' => 'user-42'])),
        ];
        $segments[] = JWT::urlsafeB64Encode(hash_hmac('sha256', implode('.', $segments), self::SECRET, true));

        return implode('.', $segments);
    }

    public function test_a_published_key_set_verifies_a_token_signed_under_a_matching_kid(): void
    {
        $authenticator = new JwtAuthenticator(self::keySetWithKids('2025-key', '2026-key'));
        $signingKey = JwtSigningKey::rsaPrivateKey(SecondRsaKeyPair::privateKey(), kid: '2026-key');
        $token = new JwtIssuer($signingKey)->issue('user-42');

        $user = $authenticator->authenticate($token);

        self::assertInstanceOf(JwtUser::class, $user);
        self::assertSame('user-42', $user->id());
    }

    /**
     * Each kid selects its own published key and no other. "0" and
     * "00" are the pair a PHP array key cannot hold apart (see
     * JwkSetParser); "ordinary" shares its key pair with "0", so a
     * token verifying under one of them is a fact about the kid the
     * document published, not about which key happens to be in the set.
     *
     * @return array<string, array{string, string, bool}>
     */
    public static function publishedKidSelections(): array
    {
        return [
            'kid 0 under its own key' => ['0', RsaKeyPair::PRIVATE_KEY, true],
            'kid 00 under its own key' => ['00', SecondRsaKeyPair::privateKey(), true],
            'an ordinary kid under its own key' => ['ordinary', RsaKeyPair::PRIVATE_KEY, true],
            'kid 0 under the key published as 00' => ['0', SecondRsaKeyPair::privateKey(), false],
            'kid 00 under the key published as 0' => ['00', RsaKeyPair::PRIVATE_KEY, false],
            'an ordinary kid under the key published as 00' => ['ordinary', SecondRsaKeyPair::privateKey(), false],
        ];
    }

    #[DataProvider('publishedKidSelections')]
    public function test_a_published_kid_selects_its_own_key(string $kid, string $privateKey, bool $verifies): void
    {
        $authenticator = new JwtAuthenticator(self::publishedKeySet([
            new PublishedRsaKey('0', RsaKeyPair::PUBLIC_KEY),
            new PublishedRsaKey('00', SecondRsaKeyPair::publicKey()),
            new PublishedRsaKey('ordinary', RsaKeyPair::PUBLIC_KEY),
        ]));
        $token = new JwtIssuer(JwtSigningKey::rsaPrivateKey($privateKey, kid: $kid))->issue('user-42');

        $user = $authenticator->authenticate($token);

        if (!$verifies) {
            self::assertNull($user);

            return;
        }

        self::assertInstanceOf(JwtUser::class, $user);
        self::assertSame('user-42', $user->id());
    }

    /**
     * Publisher, parser, signing key and header boundary hold a kid to
     * the one rule, so the longest kid JwkSet will emit is one the rest
     * of the path still accepts.
     */
    public function test_the_longest_publishable_kid_survives_the_whole_path(): void
    {
        $kid = str_repeat('k', JwtKeyValidator::MAXIMUM_KID_LENGTH);
        $authenticator = new JwtAuthenticator(self::publishedKeySet([new PublishedRsaKey($kid, RsaKeyPair::PUBLIC_KEY)]));
        $token = new JwtIssuer(JwtSigningKey::rsaPrivateKey(RsaKeyPair::PRIVATE_KEY, kid: $kid))->issue('user-42');

        $user = $authenticator->authenticate($token);

        self::assertInstanceOf(JwtUser::class, $user);
        self::assertSame('user-42', $user->id());
    }

    public function test_a_published_key_set_rejects_an_unrecognized_kid(): void
    {
        $authenticator = new JwtAuthenticator(
            self::publishedKeySet([new PublishedRsaKey('current', RsaKeyPair::PUBLIC_KEY)]),
        );
        $token = new JwtIssuer(JwtSigningKey::rsaPrivateKey(RsaKeyPair::PRIVATE_KEY, kid: 'retired'))->issue('user-42');

        self::assertNull($authenticator->authenticate($token));
    }

    public function test_a_published_key_set_rejects_a_token_carrying_no_kid_at_all(): void
    {
        $authenticator = new JwtAuthenticator(
            self::publishedKeySet([new PublishedRsaKey('current', RsaKeyPair::PUBLIC_KEY)]),
        );
        $token = new JwtIssuer(JwtSigningKey::rsaPrivateKey(RsaKeyPair::PRIVATE_KEY))->issue('user-42');

        self::assertNull($authenticator->authenticate($token));
    }

    /**
     * Header shapes JWT::decode() reaches by a path that raises a raw
     * TypeError rather than a decode failure — see JoseHeader.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function tokenControlledHeaderShapes(): array
    {
        return [
            'alg as an array' => [['alg' => ['RS256'], 'kid' => 'current']],
            'alg as an object' => [['alg' => ['name' => 'RS256'], 'kid' => 'current']],
            'alg as an integer' => [['alg' => 256, 'kid' => 'current']],
            'alg as a boolean' => [['alg' => true, 'kid' => 'current']],
            'alg as null' => [['alg' => null, 'kid' => 'current']],
            'no alg at all' => [['typ' => 'JWT', 'kid' => 'current']],
            'an algorithm this package does not support' => [['alg' => 'ES256', 'kid' => 'current']],
            'the none algorithm' => [['alg' => 'none', 'kid' => 'current']],
            'kid as an array' => [['alg' => 'RS256', 'kid' => ['current']]],
            'kid as an object' => [['alg' => 'RS256', 'kid' => ['name' => 'current']]],
            'kid as an integer' => [['alg' => 'RS256', 'kid' => 0]],
            'kid as a boolean' => [['alg' => 'RS256', 'kid' => true]],
            'kid as null' => [['alg' => 'RS256', 'kid' => null]],
            'a blank kid' => [['alg' => 'RS256', 'kid' => "  \t"]],
            'a kid past the length limit' => [
                ['alg' => 'RS256', 'kid' => str_repeat('k', JwtKeyValidator::MAXIMUM_KID_LENGTH + 1)],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $header
     */
    #[DataProvider('tokenControlledHeaderShapes')]
    public function test_a_token_controlled_header_shape_is_rejected(array $header): void
    {
        $authenticator = new JwtAuthenticator(
            self::publishedKeySet([new PublishedRsaKey('current', RsaKeyPair::PUBLIC_KEY)]),
        );

        self::assertNull($authenticator->authenticate($this->tokenWithHeader($header)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedCompactTokens(): array
    {
        $header = JWT::urlsafeB64Encode('{"alg":"HS256"}');
        $payload = JWT::urlsafeB64Encode('{"sub":"user-42"}');
        $signature = JWT::urlsafeB64Encode('signature');

        // Both of these encode a length whose final character carries
        // unused bits, so setting one leaves a different spelling of
        // the identical bytes.
        $spacedHeader = JWT::urlsafeB64Encode('{"alg": "HS256"}');
        $longSignature = JWT::urlsafeB64Encode('signatures');

        return [
            'two segments' => ["{$header}.{$payload}"],
            'four segments' => ["{$header}.{$payload}.{$signature}.{$signature}"],
            'an empty header segment' => [".{$payload}.{$signature}"],
            'an empty signature segment' => ["{$header}.{$payload}."],
            'a padded signature segment' => ["{$header}.{$payload}.{$signature}=="],
            'a header segment of an impossible length' => ["{$header}A.{$payload}.{$signature}"],
            'a header segment that is not base64url' => ["a+b.{$payload}.{$signature}"],
            'a header that is not JSON' => [JWT::urlsafeB64Encode('nonsense') . ".{$payload}.{$signature}"],
            'a header that is a JSON array' => [JWT::urlsafeB64Encode('[1,2,3]') . ".{$payload}.{$signature}"],
            'a header that is a JSON string' => [JWT::urlsafeB64Encode('"HS256"') . ".{$payload}.{$signature}"],
            'a header naming alg twice' => [
                JWT::urlsafeB64Encode('{"alg":"HS256","alg":"none"}') . ".{$payload}.{$signature}",
            ],
            'a header naming kid twice' => [
                JWT::urlsafeB64Encode('{"alg":"HS256","kid":"a","kid":"b"}') . ".{$payload}.{$signature}",
            ],
            'a token past the length limit' => [
                str_repeat('a', JoseHeader::MAXIMUM_TOKEN_LENGTH) . ".{$payload}.{$signature}",
            ],
            'a header segment past its own length limit' => [
                str_repeat('a', JoseHeader::MAXIMUM_HEADER_SEGMENT_LENGTH + 1) . ".{$payload}.{$signature}",
            ],
            'a non-canonical header spelling' => [
                self::withUnusedBitsSet($spacedHeader) . ".{$payload}.{$signature}",
            ],
            'a non-canonical signature spelling' => [
                "{$spacedHeader}.{$payload}." . self::withUnusedBitsSet($longSignature),
            ],
        ];
    }

    /**
     * Rewrites a base64url string into a second spelling of the same
     * bytes — see Base64Url.
     */
    private static function withUnusedBitsSet(string $base64Url): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
        $index = strpos($alphabet, $base64Url[strlen($base64Url) - 1]);
        self::assertIsInt($index);
        $tampered = substr($base64Url, 0, -1) . $alphabet[$index + 1];

        self::assertSame(
            base64_decode(strtr($base64Url, '-_', '+/')),
            base64_decode(strtr($tampered, '-_', '+/')),
        );

        return $tampered;
    }

    #[DataProvider('malformedCompactTokens')]
    public function test_a_malformed_compact_token_is_rejected(string $token): void
    {
        self::assertNull(new JwtAuthenticator(self::keys())->authenticate($token));
    }

    /**
     * A single-key authenticator never reads `kid`: JWT::decode()
     * returns the one key it holds before the lookup that would use it.
     * A malformed `kid` is refused anyway.
     */
    public function test_a_single_key_authenticator_refuses_a_malformed_kid_it_would_never_read(): void
    {
        $token = $this->tokenWithHeader(['alg' => 'HS256', 'kid' => ['current']]);

        self::assertNull(new JwtAuthenticator(self::keys())->authenticate($token));
    }

    /**
     * firebase/php-jwt reads neither `crit` nor `b64`, so each of these
     * tokens decodes there ignoring its declaration; the test asserts
     * that before requiring this package's boundary to refuse it.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function unimplementedProtectedHeaders(): array
    {
        return [
            'crit naming an extension' => [['crit' => ['http://example.test/exp']]],
            'crit naming b64' => [['crit' => ['b64'], 'b64' => false]],
            'an empty crit' => [['crit' => []]],
            'crit that is not a list' => [['crit' => 'b64']],
            'b64 false without crit' => [['b64' => false]],
            'b64 true without crit' => [['b64' => true]],
        ];
    }

    /**
     * @param array<string, mixed> $header
     */
    #[DataProvider('unimplementedProtectedHeaders')]
    public function test_a_header_declaring_semantics_this_package_does_not_implement_is_refused(array $header): void
    {
        $token = $this->tokenWithHeader($header + ['alg' => 'HS256']);

        $claims = JWT::decode($token, new Key(self::SECRET, 'HS256'));
        self::assertSame('user-42', $claims->sub);

        self::assertNull(new JwtAuthenticator(self::keys())->authenticate($token));
    }

    /**
     * The ordinary case: a token whose header names a supported
     * algorithm and an ordinary kid verifies against the key that kid
     * selects.
     */
    public function test_an_ordinary_kid_still_verifies_through_the_header_boundary(): void
    {
        $authenticator = new JwtAuthenticator(
            self::publishedKeySet([new PublishedRsaKey('current', RsaKeyPair::PUBLIC_KEY)]),
        );
        $signingKey = JwtSigningKey::rsaPrivateKey(RsaKeyPair::PRIVATE_KEY, kid: 'current');
        $token = new JwtIssuer($signingKey)->issue('user-42');

        $user = $authenticator->authenticate($token);

        self::assertInstanceOf(JwtUser::class, $user);
        self::assertSame('user-42', $user->id());
    }
}
