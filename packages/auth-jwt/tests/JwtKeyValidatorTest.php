<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Tests;

use ErrorException;
use Firebase\JWT\JWK;
use Kinetis\AuthJwt\Exception\JwtConfigurationException;
use Kinetis\AuthJwt\JwkSet;
use Kinetis\AuthJwt\JwtKeyValidator;
use Kinetis\AuthJwt\PublishedRsaKey;
use Kinetis\AuthJwt\Tests\Fixtures\RsaKeyPair;
use Kinetis\AuthJwt\Tests\Fixtures\UndersizedRsaKeyPair;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JwtKeyValidatorTest extends TestCase
{
    // --- Algorithm classification ---

    public function test_isHmacAlgorithm_is_true_only_for_the_hmac_algorithms(): void
    {
        self::assertTrue(JwtKeyValidator::isHmacAlgorithm('HS256'));
        self::assertTrue(JwtKeyValidator::isHmacAlgorithm('HS384'));
        self::assertTrue(JwtKeyValidator::isHmacAlgorithm('HS512'));
        self::assertFalse(JwtKeyValidator::isHmacAlgorithm('RS256'));
        self::assertFalse(JwtKeyValidator::isHmacAlgorithm('ES256'));
    }

    /**
     * An algorithm firebase/php-jwt supports but this package does not
     * must not be miscategorized as RSA just because it is not HMAC.
     */
    public function test_isRsaAlgorithm_is_true_only_for_the_rsa_algorithms(): void
    {
        self::assertTrue(JwtKeyValidator::isRsaAlgorithm('RS256'));
        self::assertTrue(JwtKeyValidator::isRsaAlgorithm('RS384'));
        self::assertTrue(JwtKeyValidator::isRsaAlgorithm('RS512'));
        self::assertFalse(JwtKeyValidator::isRsaAlgorithm('HS256'));
        self::assertFalse(JwtKeyValidator::isRsaAlgorithm('ES256'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsupportedAlgorithms(): iterable
    {
        yield 'ES256 (a real firebase/php-jwt algorithm, deliberately out of scope)' => ['ES256'];
        yield 'EdDSA' => ['EdDSA'];
        yield 'nonsense' => ['not-an-algorithm'];
        yield 'empty string' => [''];
        yield 'lowercase hs256 (case matters, unlike the Bearer scheme)' => ['hs256'];
    }

    /**
     * Each assertion pins its own algorithm family, so an unsupported
     * algorithm and an algorithm from the other family are refused the
     * same way — key material that is otherwise valid never rescues it.
     */
    #[DataProvider('unsupportedAlgorithms')]
    public function test_every_assertion_rejects_an_algorithm_outside_its_own_family(string $algorithm): void
    {
        foreach ([
            static fn () => JwtKeyValidator::assertHmacSecret($algorithm, str_repeat('a', 64)),
            static fn () => JwtKeyValidator::assertRsaPublicKey($algorithm, RsaKeyPair::PUBLIC_KEY),
            static fn () => JwtKeyValidator::assertRsaPrivateKey($algorithm, RsaKeyPair::PRIVATE_KEY),
        ] as $assertion) {
            try {
                $assertion();
                self::fail("Expected {$algorithm} to be rejected.");
            } catch (JwtConfigurationException $exception) {
                self::assertStringContainsString('is not supported here', $exception->getMessage());
            }
        }
    }

    public function test_an_rsa_algorithm_is_not_an_hmac_secret_and_the_reverse(): void
    {
        $this->expectException(JwtConfigurationException::class);

        JwtKeyValidator::assertHmacSecret('RS256', str_repeat('a', 64));
    }

    public function test_an_hmac_algorithm_is_not_an_rsa_key(): void
    {
        $this->expectException(JwtConfigurationException::class);

        JwtKeyValidator::assertRsaPublicKey('HS256', RsaKeyPair::PUBLIC_KEY);
    }

    // --- HMAC key material ---

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function hmacAlgorithmsWithMinimumBytes(): iterable
    {
        yield 'HS256' => ['HS256', 32];
        yield 'HS384' => ['HS384', 48];
        yield 'HS512' => ['HS512', 64];
    }

    #[DataProvider('hmacAlgorithmsWithMinimumBytes')]
    public function test_an_hmac_secret_is_accepted_at_the_minimum_and_refused_one_byte_below(
        string $algorithm,
        int $minimumBytes,
    ): void {
        JwtKeyValidator::assertHmacSecret($algorithm, str_repeat('a', $minimumBytes));
        JwtKeyValidator::assertHmacSecret($algorithm, str_repeat('a', 256));

        $this->expectException(JwtConfigurationException::class);

        JwtKeyValidator::assertHmacSecret($algorithm, str_repeat('a', $minimumBytes - 1));
    }

    public function test_an_empty_hmac_secret_is_rejected(): void
    {
        $this->expectException(JwtConfigurationException::class);

        JwtKeyValidator::assertHmacSecret('HS256', '');
    }

    // --- RSA key material ---

    public function test_a_2048_bit_pair_is_accepted_under_every_rsa_algorithm(): void
    {
        foreach (['RS256', 'RS384', 'RS512'] as $algorithm) {
            JwtKeyValidator::assertRsaPublicKey($algorithm, RsaKeyPair::PUBLIC_KEY);
            JwtKeyValidator::assertRsaPrivateKey($algorithm, RsaKeyPair::PRIVATE_KEY);
        }

        self::assertTrue(true);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusablePublicKeys(): iterable
    {
        yield 'a malformed PEM' => ['not a real pem'];
        yield 'an undersized 1024-bit key' => [UndersizedRsaKeyPair::PUBLIC_KEY];
        yield 'the private half of the pair' => [RsaKeyPair::PRIVATE_KEY];
    }

    #[DataProvider('unusablePublicKeys')]
    public function test_an_unusable_public_key_is_rejected(string $material): void
    {
        $this->expectException(JwtConfigurationException::class);

        JwtKeyValidator::assertRsaPublicKey('RS256', $material);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusablePrivateKeys(): iterable
    {
        yield 'a malformed PEM' => ['not a real pem'];
        yield 'an undersized 1024-bit key' => [UndersizedRsaKeyPair::PRIVATE_KEY];
        yield 'the public half of the pair' => [RsaKeyPair::PUBLIC_KEY];
    }

    #[DataProvider('unusablePrivateKeys')]
    public function test_an_unusable_private_key_is_rejected(string $material): void
    {
        $this->expectException(JwtConfigurationException::class);

        JwtKeyValidator::assertRsaPrivateKey('RS256', $material);
    }

    public function test_an_elliptic_curve_key_is_not_an_rsa_key(): void
    {
        $ecKey = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        self::assertNotFalse($ecKey);
        $details = openssl_pkey_get_details($ecKey);
        self::assertIsArray($details);

        $this->expectException(JwtConfigurationException::class);

        JwtKeyValidator::assertRsaPublicKey('RS256', (string) $details['key']);
    }

    // --- Already-parsed key objects ---
    //
    // openssl_pkey_get_public()/_private() emit a genuine E_WARNING, not
    // merely a false return, when handed a key object whose own role
    // does not match, and PHP 8's error-control operator does not keep
    // that from a custom handler. An application turning warnings into
    // exceptions is a real pattern, so the role of an object is decided
    // without ever making that call.

    /**
     * @param callable(): mixed $fn
     */
    private function withWarningsAsExceptions(callable $fn): mixed
    {
        set_error_handler(static function (int $errno, string $errstr): never {
            throw new ErrorException($errstr, 0, $errno);
        });

        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }

    public function test_a_public_key_object_is_accepted_under_a_warning_to_exception_handler(): void
    {
        $publicKeyObject = openssl_pkey_get_public(RsaKeyPair::PUBLIC_KEY);
        self::assertNotFalse($publicKeyObject);

        $this->withWarningsAsExceptions(
            static fn () => JwtKeyValidator::assertRsaPublicKey('RS256', $publicKeyObject),
        );

        self::assertTrue(true);
    }

    public function test_a_private_key_object_used_as_public_is_rejected_without_leaking_a_php_warning(): void
    {
        $privateKeyObject = openssl_pkey_get_private(RsaKeyPair::PRIVATE_KEY);
        self::assertNotFalse($privateKeyObject);

        $this->expectException(JwtConfigurationException::class);

        $this->withWarningsAsExceptions(
            static fn () => JwtKeyValidator::assertRsaPublicKey('RS256', $privateKeyObject),
        );
    }

    /**
     * Firebase\JWT\JWK::parseKeySet() hands back a Key wrapping an
     * already-parsed, public-only OpenSSLAsymmetricKey, so this is the
     * shape every key out of a parsed JWK Set is checked in.
     */
    public function test_a_parsed_jwks_public_key_is_accepted_under_a_warning_to_exception_handler(): void
    {
        $set = JwkSet::fromRsaPublicKeys([new PublishedRsaKey('current', RsaKeyPair::PUBLIC_KEY)]);
        $keys = JWK::parseKeySet($set);
        $material = $keys['current']->getKeyMaterial();
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $material);

        $this->withWarningsAsExceptions(
            static fn () => JwtKeyValidator::assertRsaPublicKey('RS256', $material),
        );

        self::assertTrue(true);
    }

    // --- Key ids ---

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function kids(): iterable
    {
        yield 'an ordinary kid' => ['2026-key', true];
        yield 'a decimal kid' => ['0', true];
        yield 'a non-ASCII kid' => ['clé-2026', true];
        yield 'the longest publishable kid' => [str_repeat('k', JwtKeyValidator::MAXIMUM_KID_LENGTH), true];
        yield 'an empty kid' => ['', false];
        yield 'a blank kid' => ["  \t", false];
        yield 'one byte past the length limit' => [str_repeat('k', JwtKeyValidator::MAXIMUM_KID_LENGTH + 1), false];
        yield 'bytes outside UTF-8' => ["key-\xFF", false];
    }

    #[DataProvider('kids')]
    public function test_one_kid_rule_answers_for_strings_and_for_decoded_values(string $kid, bool $usable): void
    {
        self::assertSame($usable, JwtKeyValidator::isUsableKid($kid));
        self::assertSame($usable, JwtKeyValidator::isUsableKidValue($kid));

        if ($usable) {
            JwtKeyValidator::assertUsableKid($kid);

            return;
        }

        $this->expectException(JwtConfigurationException::class);

        JwtKeyValidator::assertUsableKid($kid);
    }

    /**
     * A kid arrives as a decoded JSON value or a PHP array key, so its
     * stringness is itself in question.
     */
    public function test_a_kid_that_is_not_a_string_names_no_key(): void
    {
        self::assertFalse(JwtKeyValidator::isUsableKidValue(0));
        self::assertFalse(JwtKeyValidator::isUsableKidValue(null));
        self::assertFalse(JwtKeyValidator::isUsableKidValue(['2026-key']));
        self::assertFalse(JwtKeyValidator::isUsableKidValue(true));
    }
}
