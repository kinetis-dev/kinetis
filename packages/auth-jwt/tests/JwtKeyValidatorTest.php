<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Tests;

use ErrorException;
use Firebase\JWT\JWK;
use Kinetis\AuthJwt\JwkSet;
use Kinetis\AuthJwt\JwtKeyValidator;
use Kinetis\AuthJwt\PublishedRsaKey;
use Kinetis\AuthJwt\Tests\Fixtures\RsaKeyPair;
use Kinetis\AuthJwt\Tests\Fixtures\UndersizedRsaKeyPair;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class JwtKeyValidatorTest extends TestCase
{
    private function fails(): \Closure
    {
        return static fn () => new RuntimeException('fail');
    }

    // --- Algorithm support ---

    public function test_isHmacAlgorithm_is_true_for_every_hmac_algorithm(): void
    {
        self::assertTrue(JwtKeyValidator::isHmacAlgorithm('HS256'));
        self::assertTrue(JwtKeyValidator::isHmacAlgorithm('HS384'));
        self::assertTrue(JwtKeyValidator::isHmacAlgorithm('HS512'));
    }

    public function test_isHmacAlgorithm_is_false_for_every_rsa_algorithm(): void
    {
        self::assertFalse(JwtKeyValidator::isHmacAlgorithm('RS256'));
        self::assertFalse(JwtKeyValidator::isHmacAlgorithm('RS384'));
        self::assertFalse(JwtKeyValidator::isHmacAlgorithm('RS512'));
    }

    public function test_isHmacAlgorithm_is_false_for_an_unsupported_algorithm(): void
    {
        self::assertFalse(JwtKeyValidator::isHmacAlgorithm('ES256'));
    }

    public function test_isRsaAlgorithm_is_true_for_every_rsa_algorithm(): void
    {
        self::assertTrue(JwtKeyValidator::isRsaAlgorithm('RS256'));
        self::assertTrue(JwtKeyValidator::isRsaAlgorithm('RS384'));
        self::assertTrue(JwtKeyValidator::isRsaAlgorithm('RS512'));
    }

    public function test_isRsaAlgorithm_is_false_for_every_hmac_algorithm(): void
    {
        self::assertFalse(JwtKeyValidator::isRsaAlgorithm('HS256'));
        self::assertFalse(JwtKeyValidator::isRsaAlgorithm('HS384'));
        self::assertFalse(JwtKeyValidator::isRsaAlgorithm('HS512'));
    }

    public function test_isRsaAlgorithm_is_false_for_an_unsupported_algorithm(): void
    {
        // Confirms an algorithm firebase/php-jwt itself supports but
        // this package deliberately doesn't isn't miscategorized as RSA
        // just because it isn't HMAC.
        self::assertFalse(JwtKeyValidator::isRsaAlgorithm('ES256'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function supportedAlgorithms(): iterable
    {
        foreach (JwtKeyValidator::SUPPORTED_ALGORITHMS as $algorithm) {
            yield $algorithm => [$algorithm];
        }
    }

    #[DataProvider('supportedAlgorithms')]
    public function test_assertSupportedAlgorithm_accepts_every_supported_algorithm(string $algorithm): void
    {
        JwtKeyValidator::assertSupportedAlgorithm($algorithm, $this->fails());

        self::assertTrue(true);
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

    #[DataProvider('unsupportedAlgorithms')]
    public function test_assertSupportedAlgorithm_rejects_every_unsupported_algorithm(string $algorithm): void
    {
        $this->expectException(RuntimeException::class);

        JwtKeyValidator::assertSupportedAlgorithm($algorithm, $this->fails());
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
    public function test_assertKeyMaterial_accepts_an_hmac_secret_at_exactly_the_minimum(
        string $algorithm,
        int $minimumBytes,
    ): void {
        JwtKeyValidator::assertKeyMaterial($algorithm, str_repeat('a', $minimumBytes), 'public', $this->fails());
        JwtKeyValidator::assertKeyMaterial($algorithm, str_repeat('a', $minimumBytes), 'private', $this->fails());

        self::assertTrue(true);
    }

    #[DataProvider('hmacAlgorithmsWithMinimumBytes')]
    public function test_assertKeyMaterial_rejects_an_hmac_secret_one_byte_short_of_the_minimum(
        string $algorithm,
        int $minimumBytes,
    ): void {
        $this->expectException(RuntimeException::class);

        JwtKeyValidator::assertKeyMaterial($algorithm, str_repeat('a', $minimumBytes - 1), 'public', $this->fails());
    }

    public function test_assertKeyMaterial_rejects_an_empty_hmac_secret(): void
    {
        $this->expectException(RuntimeException::class);

        JwtKeyValidator::assertKeyMaterial('HS256', '', 'public', $this->fails());
    }

    public function test_assertKeyMaterial_accepts_a_generously_long_hmac_secret(): void
    {
        JwtKeyValidator::assertKeyMaterial('HS256', str_repeat('a', 256), 'public', $this->fails());

        self::assertTrue(true);
    }

    // --- RSA key material ---

    public function test_assertKeyMaterial_accepts_a_valid_2048_bit_public_key(): void
    {
        JwtKeyValidator::assertKeyMaterial('RS256', RsaKeyPair::PUBLIC_KEY, 'public', $this->fails());

        self::assertTrue(true);
    }

    public function test_assertKeyMaterial_accepts_a_valid_2048_bit_private_key(): void
    {
        JwtKeyValidator::assertKeyMaterial('RS256', RsaKeyPair::PRIVATE_KEY, 'private', $this->fails());

        self::assertTrue(true);
    }

    public function test_assertKeyMaterial_accepts_a_2048_bit_key_for_every_rsa_algorithm(): void
    {
        foreach (['RS256', 'RS384', 'RS512'] as $algorithm) {
            JwtKeyValidator::assertKeyMaterial($algorithm, RsaKeyPair::PUBLIC_KEY, 'public', $this->fails());
        }

        self::assertTrue(true);
    }

    public function test_assertKeyMaterial_rejects_an_undersized_1024_bit_public_key(): void
    {
        $this->expectException(RuntimeException::class);

        JwtKeyValidator::assertKeyMaterial('RS256', UndersizedRsaKeyPair::PUBLIC_KEY, 'public', $this->fails());
    }

    public function test_assertKeyMaterial_rejects_an_undersized_1024_bit_private_key(): void
    {
        $this->expectException(RuntimeException::class);

        JwtKeyValidator::assertKeyMaterial('RS256', UndersizedRsaKeyPair::PRIVATE_KEY, 'private', $this->fails());
    }

    public function test_assertKeyMaterial_rejects_a_malformed_pem_as_a_public_key(): void
    {
        $this->expectException(RuntimeException::class);

        JwtKeyValidator::assertKeyMaterial('RS256', 'not a real pem', 'public', $this->fails());
    }

    public function test_assertKeyMaterial_rejects_a_malformed_pem_as_a_private_key(): void
    {
        $this->expectException(RuntimeException::class);

        JwtKeyValidator::assertKeyMaterial('RS256', 'not a real pem', 'private', $this->fails());
    }

    public function test_assertKeyMaterial_rejects_a_public_key_string_used_with_the_private_role(): void
    {
        // JwtIssuer's own $key is always a raw PEM string when $role is
        // 'private' — a public key handed in under that role must still
        // be rejected, not silently accepted as though it could sign.
        $this->expectException(RuntimeException::class);

        JwtKeyValidator::assertKeyMaterial('RS256', RsaKeyPair::PUBLIC_KEY, 'private', $this->fails());
    }

    public function test_assertKeyMaterial_accepts_an_already_parsed_public_key_object(): void
    {
        $keyObject = openssl_pkey_get_public(RsaKeyPair::PUBLIC_KEY);
        self::assertNotFalse($keyObject);

        JwtKeyValidator::assertKeyMaterial('RS256', $keyObject, 'public', $this->fails());

        self::assertTrue(true);
    }

    public function test_assertKeyMaterial_rejects_an_ec_key_as_not_rsa(): void
    {
        $ecKey = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        self::assertNotFalse($ecKey);
        $details = openssl_pkey_get_details($ecKey);
        self::assertIsArray($details);

        $this->expectException(RuntimeException::class);

        JwtKeyValidator::assertKeyMaterial('RS256', $details['key'], 'public', $this->fails());
    }

    // --- assertKeyMaterial() must be sound standalone, never trusting
    // --- that assertSupportedAlgorithm() ran first or that $role was
    // --- spelled correctly ---

    public function test_assertKeyMaterial_rejects_an_unsupported_algorithm_even_with_otherwise_valid_rsa_material(): void
    {
        // A real gap in an earlier version of this class: treating "not
        // HMAC" as "must be RSA" meant a genuinely unsupported algorithm
        // (ES256) paired with valid RSA key material passed silently,
        // since the RSA branch never itself checked whether $algorithm
        // was actually supported at all.
        $this->expectException(RuntimeException::class);

        JwtKeyValidator::assertKeyMaterial('ES256', RsaKeyPair::PUBLIC_KEY, 'public', $this->fails());
    }

    public function test_assertKeyMaterial_rejects_an_unsupported_algorithm_and_nonsense_role_together(): void
    {
        $this->expectException(RuntimeException::class);

        JwtKeyValidator::assertKeyMaterial('not-an-algorithm', RsaKeyPair::PUBLIC_KEY, 'banana', $this->fails());
    }

    public function test_assertKeyMaterial_rejects_a_role_outside_public_or_private(): void
    {
        $this->expectException(RuntimeException::class);

        JwtKeyValidator::assertKeyMaterial('RS256', RsaKeyPair::PUBLIC_KEY, 'banana', $this->fails());
    }

    public function test_assertKeyMaterial_rejects_a_wrong_case_role(): void
    {
        // 'Public' isn't the literal 'public' this class recognizes — a
        // typo'd role must be rejected, not silently treated as though
        // it meant one of the two real roles.
        $this->expectException(RuntimeException::class);

        JwtKeyValidator::assertKeyMaterial('RS256', RsaKeyPair::PUBLIC_KEY, 'Public', $this->fails());
    }

    // --- Object-valued RSA material must never leak a PHP warning as
    // --- an unrelated exception under a warning-to-exception error
    // --- handler — a real, not hypothetical, application pattern ---

    /**
     * Installs a handler that turns every PHP warning into an
     * ErrorException for the duration of $fn — the exact adversarial
     * scenario a real application's own error handler can create.
     * Confirmed directly (a standalone reproduction, before writing any
     * of these tests) that openssl_pkey_get_public()/_private() emits a
     * genuine E_WARNING, not merely a false return, when handed an
     * already-parsed key object whose own role doesn't match — and that
     * the @ operator does NOT suppress it from reaching a custom error
     * handler under PHP 8's own error-control semantics, so this proof
     * has to avoid ever making that call in the first place, not merely
     * silence it.
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

    public function test_assertKeyMaterial_rejects_a_private_key_object_used_as_public_without_leaking_a_php_warning(): void
    {
        $privateKeyObject = openssl_pkey_get_private(RsaKeyPair::PRIVATE_KEY);
        self::assertNotFalse($privateKeyObject);

        $this->expectException(RuntimeException::class);

        $this->withWarningsAsExceptions(
            fn () => JwtKeyValidator::assertKeyMaterial('RS256', $privateKeyObject, 'public', $this->fails()),
        );
    }

    public function test_assertKeyMaterial_rejects_a_public_key_object_used_as_private_without_leaking_a_php_warning(): void
    {
        $publicKeyObject = openssl_pkey_get_public(RsaKeyPair::PUBLIC_KEY);
        self::assertNotFalse($publicKeyObject);

        $this->expectException(RuntimeException::class);

        $this->withWarningsAsExceptions(
            fn () => JwtKeyValidator::assertKeyMaterial('RS256', $publicKeyObject, 'private', $this->fails()),
        );
    }

    public function test_assertKeyMaterial_accepts_a_public_key_object_as_public_under_a_warning_to_exception_handler(): void
    {
        $publicKeyObject = openssl_pkey_get_public(RsaKeyPair::PUBLIC_KEY);
        self::assertNotFalse($publicKeyObject);

        $this->withWarningsAsExceptions(
            fn () => JwtKeyValidator::assertKeyMaterial('RS256', $publicKeyObject, 'public', $this->fails()),
        );

        self::assertTrue(true);
    }

    public function test_assertKeyMaterial_accepts_a_private_key_object_as_private_under_a_warning_to_exception_handler(): void
    {
        $privateKeyObject = openssl_pkey_get_private(RsaKeyPair::PRIVATE_KEY);
        self::assertNotFalse($privateKeyObject);

        $this->withWarningsAsExceptions(
            fn () => JwtKeyValidator::assertKeyMaterial('RS256', $privateKeyObject, 'private', $this->fails()),
        );

        self::assertTrue(true);
    }

    /**
     * The exact real-world shape this all exists for: Firebase\JWT\JWK::
     * parseKeySet() itself hands back a Key wrapping an already-parsed,
     * public-only OpenSSLAsymmetricKey (confirmed directly by reading
     * its source) — proving that shape verifies cleanly, even under the
     * adversarial handler, is what makes the JwkSet round-trip tests
     * elsewhere in this suite trustworthy.
     */
    public function test_assertKeyMaterial_accepts_a_real_parsed_jwks_public_key_under_a_warning_to_exception_handler(): void
    {
        $set = JwkSet::fromRsaPublicKeys([new PublishedRsaKey('current', RsaKeyPair::PUBLIC_KEY)]);
        $keys = JWK::parseKeySet($set);
        $material = $keys['current']->getKeyMaterial();
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $material);

        $this->withWarningsAsExceptions(
            fn () => JwtKeyValidator::assertKeyMaterial('RS256', $material, 'public', $this->fails()),
        );

        self::assertTrue(true);
    }
}
