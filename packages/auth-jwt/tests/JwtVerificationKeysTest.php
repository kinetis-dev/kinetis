<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Tests;

use ErrorException;
use Kinetis\AuthJwt\Exception\JwtConfigurationException;
use Kinetis\AuthJwt\JwkSet;
use Kinetis\AuthJwt\JwtSigningKey;
use Kinetis\AuthJwt\JwtVerificationKeys;
use Kinetis\AuthJwt\PublishedRsaKey;
use Kinetis\AuthJwt\Tests\Fixtures\RsaKeyPair;
use Kinetis\AuthJwt\Tests\Fixtures\SecondRsaKeyPair;
use Kinetis\AuthJwt\Tests\Fixtures\UndersizedRsaKeyPair;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every rejection here happens where the keys are configured, so a
 * misconfigured verifier can never become a live 401 loop masking a
 * server-side mistake.
 */
final class JwtVerificationKeysTest extends TestCase
{
    private const string LONG_SECRET = 'this-is-a-generously-long-test-secret-key-well-over-64-bytes-do-not-use-in-production';

    /**
     * @param list<PublishedRsaKey> $keys
     */
    private static function publishedKeySet(array $keys): JwtVerificationKeys
    {
        return JwtVerificationKeys::jwks((string) json_encode(JwkSet::fromRsaPublicKeys($keys), JSON_THROW_ON_ERROR));
    }

    public function test_a_single_key_verifies_a_token_whatever_kid_it_carries(): void
    {
        $keys = JwtVerificationKeys::hmacSecret(self::LONG_SECRET);
        $signingKey = JwtSigningKey::hmacSecret(self::LONG_SECRET, kid: 'unread');

        self::assertFalse($keys->requiresKid());
        self::assertSame('user-42', $keys->decode($signingKey->sign(['sub' => 'user-42']), 'unread')?->sub);
        self::assertSame('user-42', $keys->decode($signingKey->sign(['sub' => 'user-42']), null)?->sub);
    }

    public function test_a_public_key_verifies_only_what_its_own_private_half_signed(): void
    {
        $keys = JwtVerificationKeys::rsaPublicKey(RsaKeyPair::PUBLIC_KEY);

        $own = JwtSigningKey::rsaPrivateKey(RsaKeyPair::PRIVATE_KEY)->sign(['sub' => 'user-42']);
        $foreign = JwtSigningKey::rsaPrivateKey(SecondRsaKeyPair::privateKey())->sign(['sub' => 'user-42']);

        self::assertSame('user-42', $keys->decode($own, null)?->sub);
        self::assertNull($keys->decode($foreign, null));
    }

    /**
     * A single key pins its own algorithm: a token whose header names a
     * different one does not verify against it, however it was signed.
     */
    public function test_a_token_naming_another_algorithm_does_not_verify(): void
    {
        $keys = JwtVerificationKeys::rsaPublicKey(RsaKeyPair::PUBLIC_KEY);
        $token = JwtSigningKey::hmacSecret(self::LONG_SECRET)->sign(['sub' => 'user-42']);

        self::assertNull($keys->decode($token, null));
    }

    public function test_a_jwk_set_selects_by_kid_and_refuses_a_token_without_one(): void
    {
        $keys = self::publishedKeySet([
            new PublishedRsaKey('2025-key', RsaKeyPair::PUBLIC_KEY),
            new PublishedRsaKey('2026-key', SecondRsaKeyPair::publicKey()),
        ]);
        $signingKey = JwtSigningKey::rsaPrivateKey(SecondRsaKeyPair::privateKey(), kid: '2026-key');
        $token = $signingKey->sign(['sub' => 'user-42']);

        self::assertTrue($keys->requiresKid());
        self::assertSame('user-42', $keys->decode($token, '2026-key')?->sub);
        self::assertNull($keys->decode($token, '2025-key'));
        self::assertNull($keys->decode($token, 'retired'));
        self::assertNull($keys->decode($token, null));
    }

    /**
     * A kid is matched as the exact string the document published, so
     * the pair a PHP array key cannot hold apart stays two keys.
     */
    public function test_the_kids_0_and_00_select_their_own_keys(): void
    {
        $keys = self::publishedKeySet([
            new PublishedRsaKey('0', RsaKeyPair::PUBLIC_KEY),
            new PublishedRsaKey('00', SecondRsaKeyPair::publicKey()),
        ]);
        $token = JwtSigningKey::rsaPrivateKey(RsaKeyPair::PRIVATE_KEY, kid: '0')->sign(['sub' => 'user-42']);

        self::assertSame('user-42', $keys->decode($token, '0')?->sub);
        self::assertNull($keys->decode($token, '00'));
    }

    /**
     * @return iterable<string, array{callable(): JwtVerificationKeys}>
     */
    public static function unusableConfigurations(): iterable
    {
        yield 'an algorithm outside the supported six' => [
            static fn () => JwtVerificationKeys::hmacSecret(self::LONG_SECRET, 'ES256'),
        ];
        yield 'an RSA algorithm on an HMAC secret' => [
            static fn () => JwtVerificationKeys::hmacSecret(self::LONG_SECRET, 'RS256'),
        ];
        yield 'an HMAC algorithm on an RSA key' => [
            static fn () => JwtVerificationKeys::rsaPublicKey(RsaKeyPair::PUBLIC_KEY, 'HS256'),
        ];
        yield 'a too-short HMAC secret' => [static fn () => JwtVerificationKeys::hmacSecret(str_repeat('a', 16))];
        yield 'an empty HMAC secret' => [static fn () => JwtVerificationKeys::hmacSecret('')];
        yield 'a malformed public key' => [static fn () => JwtVerificationKeys::rsaPublicKey('not a real pem')];
        yield 'an undersized public key' => [
            static fn () => JwtVerificationKeys::rsaPublicKey(UndersizedRsaKeyPair::PUBLIC_KEY),
        ];
        yield 'the private half of the pair' => [
            static fn () => JwtVerificationKeys::rsaPublicKey(RsaKeyPair::PRIVATE_KEY),
        ];
        yield 'a JWK Set that is not a JWK Set' => [static fn () => JwtVerificationKeys::jwks('{}')];
    }

    /**
     * @param callable(): JwtVerificationKeys $build
     */
    #[DataProvider('unusableConfigurations')]
    public function test_an_unusable_configuration_is_refused(callable $build): void
    {
        $this->expectException(JwtConfigurationException::class);

        $build();
    }

    /**
     * An application error handler turning warnings into exceptions must
     * not see one escape in place of this package's own failure when the
     * wrong half of a key pair is configured.
     */
    public function test_the_wrong_half_of_a_pair_is_refused_without_leaking_a_php_warning(): void
    {
        set_error_handler(static function (int $errno, string $errstr): never {
            throw new ErrorException($errstr, 0, $errno);
        });

        try {
            $this->expectException(JwtConfigurationException::class);

            JwtVerificationKeys::rsaPublicKey(RsaKeyPair::PRIVATE_KEY);
        } finally {
            restore_error_handler();
        }
    }

    public function test_a_rejection_never_carries_the_key_material(): void
    {
        $secret = 'too-short-to-use';

        try {
            JwtVerificationKeys::hmacSecret($secret);
            self::fail('Expected a JwtConfigurationException.');
        } catch (JwtConfigurationException $exception) {
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString($secret, (string) $exception);
        }
    }
}
