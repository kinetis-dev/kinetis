<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Tests;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Kinetis\AuthJwt\Exception\JwtConfigurationException;
use Kinetis\AuthJwt\JwtKeyValidator;
use Kinetis\AuthJwt\JwtSigningKey;
use Kinetis\AuthJwt\Tests\Fixtures\RsaKeyPair;
use Kinetis\AuthJwt\Tests\Fixtures\UndersizedRsaKeyPair;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every rejection here happens where the key is configured, so a
 * misconfigured signing key can never reach a real issue() call.
 */
final class JwtSigningKeyTest extends TestCase
{
    private const string LONG_SECRET = 'this-is-a-generously-long-test-secret-key-well-over-64-bytes-do-not-use-in-production';

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
    public function test_an_hmac_secret_signs_a_token_the_same_secret_verifies(string $algorithm): void
    {
        $token = JwtSigningKey::hmacSecret(self::LONG_SECRET, $algorithm)->sign(['sub' => 'user-42']);

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
    public function test_a_private_key_signs_a_token_its_public_half_verifies(string $algorithm): void
    {
        $token = JwtSigningKey::rsaPrivateKey(RsaKeyPair::PRIVATE_KEY, $algorithm)->sign(['sub' => 'user-42']);

        $claims = JWT::decode($token, new Key(RsaKeyPair::PUBLIC_KEY, $algorithm));

        self::assertSame('user-42', $claims->sub);
    }

    public function test_a_configured_kid_is_written_into_every_token_header(): void
    {
        $token = JwtSigningKey::hmacSecret(self::LONG_SECRET, kid: 'key-2026')->sign(['sub' => 'user-42']);

        // JWT::decode() only writes back into $headers when it is
        // already non-null going in.
        $headers = new \stdClass();
        JWT::decode($token, new Key(self::LONG_SECRET, 'HS256'), $headers);

        self::assertSame('key-2026', $headers->kid);
    }

    public function test_no_kid_header_by_default(): void
    {
        $token = JwtSigningKey::hmacSecret(self::LONG_SECRET)->sign(['sub' => 'user-42']);

        $headers = new \stdClass();
        JWT::decode($token, new Key(self::LONG_SECRET, 'HS256'), $headers);

        self::assertFalse(property_exists($headers, 'kid'));
    }

    /**
     * @return iterable<string, array{callable(): JwtSigningKey}>
     */
    public static function unusableConfigurations(): iterable
    {
        yield 'an algorithm outside the supported six' => [
            static fn () => JwtSigningKey::hmacSecret(self::LONG_SECRET, 'ES256'),
        ];
        yield 'an RSA algorithm on an HMAC secret' => [
            static fn () => JwtSigningKey::hmacSecret(self::LONG_SECRET, 'RS256'),
        ];
        yield 'an HMAC algorithm on an RSA key' => [
            static fn () => JwtSigningKey::rsaPrivateKey(RsaKeyPair::PRIVATE_KEY, 'HS256'),
        ];
        yield 'a too-short HMAC secret' => [static fn () => JwtSigningKey::hmacSecret(str_repeat('a', 16))];
        yield 'an empty HMAC secret' => [static fn () => JwtSigningKey::hmacSecret('')];
        yield 'a malformed private key' => [static fn () => JwtSigningKey::rsaPrivateKey('not a real pem')];
        yield 'an undersized private key' => [
            static fn () => JwtSigningKey::rsaPrivateKey(UndersizedRsaKeyPair::PRIVATE_KEY),
        ];
        yield 'the public half of the pair' => [
            static fn () => JwtSigningKey::rsaPrivateKey(RsaKeyPair::PUBLIC_KEY),
        ];
    }

    /**
     * @param callable(): JwtSigningKey $build
     */
    #[DataProvider('unusableConfigurations')]
    public function test_an_unusable_configuration_is_refused(callable $build): void
    {
        $this->expectException(JwtConfigurationException::class);

        $build();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unusableKids(): array
    {
        return [
            'empty' => [''],
            'blank' => ["  \t"],
            'past the length limit' => [str_repeat('k', JwtKeyValidator::MAXIMUM_KID_LENGTH + 1)],
            'not valid UTF-8' => ["key-\xFF"],
        ];
    }

    /**
     * The kid rule is JwtKeyValidator::isUsableKid(), the same one
     * JwkSet and a parsed JWK Set apply, so a signing key cannot stamp
     * a kid its own verifier would refuse to select.
     */
    #[DataProvider('unusableKids')]
    public function test_a_kid_no_verifier_could_select_is_refused(string $kid): void
    {
        $this->expectException(JwtConfigurationException::class);
        $this->expectExceptionMessage('non-blank, valid UTF-8');

        JwtSigningKey::hmacSecret(self::LONG_SECRET, kid: $kid);
    }

    /**
     * A failure names the rule, never the secret it was checking.
     */
    public function test_a_rejection_never_carries_the_key_material(): void
    {
        $secret = 'too-short-to-use';

        try {
            JwtSigningKey::hmacSecret($secret);
            self::fail('Expected a JwtConfigurationException.');
        } catch (JwtConfigurationException $exception) {
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString($secret, (string) $exception);
        }
    }
}
