<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt;

use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use Throwable;

/**
 * The one place this package's cryptographic configuration is
 * validated — shared by JwtIssuer, JwtAuthMiddleware, JwkSet,
 * ParsedJwkSet, and JoseHeader, so the supported-algorithm set, the
 * key-material rules, and the rule for what may name a key exist once
 * rather than as independently-driftable copies.
 *
 * Six algorithms are supported: HS256/HS384/HS512 (a shared HMAC
 * secret) and RS256/RS384/RS512 (an RSA key pair). firebase/php-jwt
 * also implements ES256/ES256K/ES384/PS256/EdDSA, which are out of
 * scope here — elliptic-curve and Ed25519 keys need curve and point
 * checks rather than an HMAC byte length or an RSA modulus size, and
 * JwkSet has no representation for any of them.
 *
 * An HMAC secret must be at least as long, in bytes, as the algorithm's
 * own digest output — RFC 7518 §3.2's stated minimum: 32/48/64 bytes
 * for HS256/HS384/HS512. An RSA key must parse as an RSA key of at
 * least RSA_MINIMUM_BITS, which current cryptographic guidance treats
 * as a floor rather than a recommendation.
 *
 * Every assertion takes the caller's own exception factory rather than
 * throwing a shared type, so each caller keeps its own named exception
 * and no message here can embed the secret or key material.
 *
 * Both assertions are total: assertKeyMaterial() checks $algorithm and
 * $role itself rather than assuming assertSupportedAlgorithm() ran
 * first, so calling it alone cannot return a false-valid result for an
 * algorithm or role this class does not recognize.
 */
final class JwtKeyValidator
{
    public const array SUPPORTED_ALGORITHMS = ['HS256', 'HS384', 'HS512', 'RS256', 'RS384', 'RS512'];

    private const array HMAC_MINIMUM_KEY_BYTES = ['HS256' => 32, 'HS384' => 48, 'HS512' => 64];

    private const array KEY_ROLES = ['public', 'private'];

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

    /**
     * The one rule for what may name a key here: a non-blank string of
     * at most MAXIMUM_KID_LENGTH bytes that is valid UTF-8. Every side
     * of a rotation holds to it — JwtIssuer stamping a `kid` header,
     * JwkSet publishing one, ParsedJwkSet and JwtAuthMiddleware
     * selecting against one — so no side can name a key another would
     * refuse.
     *
     * UTF-8 is part of it because a kid travels as JSON both ways:
     * json_encode() fails on invalid bytes and json_decode() never
     * produces them, so a kid outside UTF-8 names a key no document
     * could carry.
     *
     * Takes a string, since a caller holding configuration already has
     * one; isUsableKidValue() is the same rule for a caller that does
     * not.
     */
    public static function isUsableKid(string $kid): bool
    {
        return trim($kid) !== ''
            && strlen($kid) <= self::MAXIMUM_KID_LENGTH
            && preg_match('//u', $kid) === 1;
    }

    /**
     * The same rule, for a kid whose stringness is itself in question:
     * a `kid` member read out of a decoded JOSE header or JWK, where a
     * sender writes any JSON value it likes, and a configured key map's
     * own array key, which PHP hands back as an int for a kid spelled
     * in canonical decimal form. Neither names a key here, so the check
     * answers false and hands a caller past it the string type.
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
        return in_array($algorithm, self::SUPPORTED_ALGORITHMS, true) && !self::isHmacAlgorithm($algorithm);
    }

    /**
     * @param callable(): Throwable $exceptionFactory
     */
    public static function assertSupportedAlgorithm(string $algorithm, callable $exceptionFactory): void
    {
        if (!in_array($algorithm, self::SUPPORTED_ALGORITHMS, true)) {
            throw $exceptionFactory();
        }
    }

    /**
     * Validates $material against $algorithm's own requirements. $role
     * ('private'|'public') only matters for an RSA algorithm, where it
     * selects which half is expected — an HMAC secret serves both roles
     * identically, so $role is ignored there but still has to be one of
     * the two recognized values, so a misspelled role cannot pass.
     *
     * @param callable(): Throwable $exceptionFactory
     */
    public static function assertKeyMaterial(
        string $algorithm,
        string|OpenSSLAsymmetricKey|OpenSSLCertificate $material,
        string $role,
        callable $exceptionFactory,
    ): void {
        if (!in_array($algorithm, self::SUPPORTED_ALGORITHMS, true) || !in_array($role, self::KEY_ROLES, true)) {
            throw $exceptionFactory();
        }

        if (self::isHmacAlgorithm($algorithm)) {
            if (!is_string($material) || strlen($material) < self::HMAC_MINIMUM_KEY_BYTES[$algorithm]) {
                throw $exceptionFactory();
            }

            return;
        }

        $key = self::resolveRsaKey($material, $role);

        if ($key === false) {
            throw $exceptionFactory();
        }

        $details = openssl_pkey_get_details($key);

        if (
            $details === false
            || $details['type'] !== OPENSSL_KEYTYPE_RSA
            || $details['bits'] < self::RSA_MINIMUM_BITS
        ) {
            throw $exceptionFactory();
        }
    }

    /**
     * Resolves $material to an OpenSSLAsymmetricKey matching $role.
     *
     * An already-parsed key object never goes through
     * openssl_pkey_get_public()/openssl_pkey_get_private(): those emit
     * an E_WARNING, not only a failed return, when the object's own
     * role doesn't match the one asked for, and an error handler that
     * turns warnings into exceptions would let that escape in place of
     * this class's own failure. Firebase\JWT\JWK::parseKeySet() hands
     * back exactly that shape for every RSA key in a JWKS, so it is the
     * ordinary input here.
     *
     * openssl_pkey_get_details() reads any key object regardless of
     * role, so an object's role comes from whether it carries a private
     * "d" component and is compared against $role directly — refusing a
     * private-key object for 'public' and a public-only object for
     * 'private', the same outcome without the warning.
     */
    private static function resolveRsaKey(
        string|OpenSSLAsymmetricKey|OpenSSLCertificate $material,
        string $role,
    ): OpenSSLAsymmetricKey|false {
        if (is_string($material)) {
            return $role === 'private' ? openssl_pkey_get_private($material) : openssl_pkey_get_public($material);
        }

        if ($material instanceof OpenSSLCertificate) {
            // A certificate carries only a public key, so there is no
            // role for it to mismatch.
            return $role === 'private' ? false : openssl_pkey_get_public($material);
        }

        $details = openssl_pkey_get_details($material);
        $isPrivateKeyObject = $details !== false && isset($details['rsa']['d']);

        return $isPrivateKeyObject === ($role === 'private') ? $material : false;
    }
}
