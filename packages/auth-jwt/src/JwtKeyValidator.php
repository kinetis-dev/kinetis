<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt;

use Kinetis\AuthJwt\Exception\JwtConfigurationException;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;

/**
 * The one place this package's cryptographic rules live — the supported
 * algorithms, the key-material requirements, and what may name a key —
 * shared by JwtSigningKey, JwtVerificationKeys, JwkSet, JwkSetParser and
 * JoseHeader so they cannot drift apart.
 *
 * Six algorithms are supported: HS256/HS384/HS512 (a shared HMAC
 * secret) and RS256/RS384/RS512 (an RSA key pair). firebase/php-jwt also
 * implements ES256/ES256K/ES384/PS256/EdDSA, which are out of scope:
 * elliptic-curve and Ed25519 keys need curve and point checks rather
 * than an HMAC byte length or an RSA modulus size, and JwkSet has no
 * representation for any of them.
 *
 * An HMAC secret must be at least as long, in bytes, as the algorithm's
 * own digest output — RFC 7518 §3.2's stated minimum. An RSA key must
 * parse as an RSA key of at least RSA_MINIMUM_BITS.
 *
 * Each assertion checks the algorithm itself, so calling one alone
 * cannot return a false-valid result.
 */
final class JwtKeyValidator
{
    public const array SUPPORTED_ALGORITHMS = ['HS256', 'HS384', 'HS512', 'RS256', 'RS384', 'RS512'];

    /**
     * Public so JwkSet can hold its own published keys to the identical
     * minimum, rather than duplicating the number.
     */
    public const int RSA_MINIMUM_BITS = 2048;

    /**
     * Far longer than any real key ID, and finite: a kid crosses this
     * package in a JOSE header a sender controls.
     */
    public const int MAXIMUM_KID_LENGTH = 256;

    private const array HMAC_MINIMUM_KEY_BYTES = ['HS256' => 32, 'HS384' => 48, 'HS512' => 64];

    private const array RSA_ALGORITHMS = ['RS256', 'RS384', 'RS512'];

    /**
     * The one rule for what may name a key here: a non-blank string of
     * at most MAXIMUM_KID_LENGTH bytes that is valid UTF-8. Every side
     * of a rotation holds to it — a signing key stamping a `kid`
     * header, JwkSet publishing one, JwkSetParser and
     * JwtVerificationKeys selecting against one — so no side can name a
     * key another would refuse.
     *
     * UTF-8 is part of it because a kid travels as JSON both ways:
     * json_encode() fails on invalid bytes and json_decode() never
     * produces them, so a kid outside UTF-8 names a key no document
     * could carry.
     */
    public static function isUsableKid(string $kid): bool
    {
        return trim($kid) !== ''
            && strlen($kid) <= self::MAXIMUM_KID_LENGTH
            && preg_match('//u', $kid) === 1;
    }

    /**
     * The same rule, for a kid whose stringness is itself in question: a
     * `kid` member read out of a decoded JOSE header or JWK, where a
     * sender writes any JSON value it likes. Answers false for anything
     * else and hands a caller past it the string type.
     *
     * @phpstan-assert-if-true string $kid
     */
    public static function isUsableKidValue(mixed $kid): bool
    {
        return is_string($kid) && self::isUsableKid($kid);
    }

    /**
     * @phpstan-assert-if-true 'HS256'|'HS384'|'HS512' $algorithm
     */
    public static function isHmacAlgorithm(string $algorithm): bool
    {
        return isset(self::HMAC_MINIMUM_KEY_BYTES[$algorithm]);
    }

    public static function isRsaAlgorithm(string $algorithm): bool
    {
        return in_array($algorithm, self::RSA_ALGORITHMS, true);
    }

    public static function assertUsableKid(string $kid): void
    {
        if (!self::isUsableKid($kid)) {
            throw JwtConfigurationException::invalidKid(
                'a kid must be non-blank, valid UTF-8, and at most ' . self::MAXIMUM_KID_LENGTH . ' bytes',
            );
        }
    }

    public static function assertHmacSecret(string $algorithm, #[\SensitiveParameter] string $secret): void
    {
        if (!self::isHmacAlgorithm($algorithm)) {
            throw JwtConfigurationException::unsupportedAlgorithm(
                $algorithm,
                implode(', ', array_keys(self::HMAC_MINIMUM_KEY_BYTES)),
            );
        }

        $minimumBytes = self::HMAC_MINIMUM_KEY_BYTES[$algorithm];

        if (strlen($secret) < $minimumBytes) {
            throw JwtConfigurationException::unusableKeyMaterial(
                "RFC 7518 §3.2 requires an {$algorithm} secret of at least {$minimumBytes} bytes",
            );
        }
    }

    /**
     * $material is a PEM string, or the already-parsed key object
     * Firebase\JWT\JWK::parseKeySet() produces for a key in a JWKS.
     */
    public static function assertRsaPublicKey(
        string $algorithm,
        #[\SensitiveParameter] string|OpenSSLAsymmetricKey|OpenSSLCertificate $material,
    ): void {
        self::assertRsaKey($algorithm, $material, private: false);
    }

    public static function assertRsaPrivateKey(string $algorithm, #[\SensitiveParameter] string $material): void
    {
        self::assertRsaKey($algorithm, $material, private: true);
    }

    private static function assertRsaKey(
        string $algorithm,
        #[\SensitiveParameter] string|OpenSSLAsymmetricKey|OpenSSLCertificate $material,
        bool $private,
    ): void {
        if (!self::isRsaAlgorithm($algorithm)) {
            throw JwtConfigurationException::unsupportedAlgorithm($algorithm, implode(', ', self::RSA_ALGORITHMS));
        }

        $half = $private ? 'private' : 'public';
        $key = self::resolveRsaKey($material, $private);
        $details = $key === false ? false : openssl_pkey_get_details($key);

        if (
            $details === false
            || $details['type'] !== OPENSSL_KEYTYPE_RSA
            || $details['bits'] < self::RSA_MINIMUM_BITS
        ) {
            throw JwtConfigurationException::unusableKeyMaterial(
                "{$algorithm} needs the {$half} half of an RSA key pair of at least "
                . self::RSA_MINIMUM_BITS . ' bits, and this material is unparseable, not RSA, the wrong '
                . 'half, or undersized',
            );
        }
    }

    /**
     * An already-parsed key object never goes through
     * openssl_pkey_get_public()/openssl_pkey_get_private(): those emit
     * an E_WARNING, not only a failed return, when the object's own role
     * does not match the one asked for, and an error handler that turns
     * warnings into exceptions would let that escape in place of this
     * package's own failure. openssl_pkey_get_details() reads any key
     * object regardless of role, so an object's role comes from whether
     * it carries a private "d" component.
     */
    private static function resolveRsaKey(
        #[\SensitiveParameter] string|OpenSSLAsymmetricKey|OpenSSLCertificate $material,
        bool $private,
    ): OpenSSLAsymmetricKey|false {
        if (is_string($material)) {
            return $private ? openssl_pkey_get_private($material) : openssl_pkey_get_public($material);
        }

        if ($material instanceof OpenSSLCertificate) {
            // A certificate carries only a public key, so there is no
            // role for it to mismatch.
            return $private ? false : openssl_pkey_get_public($material);
        }

        $details = openssl_pkey_get_details($material);
        $isPrivateKeyObject = $details !== false && isset($details['rsa']['d']);

        return $isPrivateKeyObject === $private ? $material : false;
    }
}
