<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt;

use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use Throwable;

/**
 * The one place this package's cryptographic configuration is actually
 * validated — shared by JwtIssuer, JwtAuthMiddleware, and JwkSet, so the
 * supported-algorithm set and key-material rules exist once rather than
 * as three independently-driftable copies.
 *
 * Exactly six algorithms are supported — the ones this package's docs
 * have always named: HS256/HS384/HS512 (a shared HMAC secret) and
 * RS256/RS384/RS512 (an RSA key pair). firebase/php-jwt itself also
 * implements ES256/ES256K/ES384/PS256/EdDSA; those are deliberately out
 * of scope here, not silently unsupported — elliptic-curve and Ed25519
 * keys need entirely different validation (curve/point checks, not an
 * HMAC byte length or an RSA modulus size), and JwkSet has no
 * representation for any of them.
 *
 * An HMAC secret must be at least as long, in bytes, as the algorithm's
 * own digest output — RFC 7518 §3.2's stated minimum ("A key of the
 * same size as the hash output... or larger MUST be used"): 32/48/64
 * bytes for HS256/HS384/HS512. An RSA key must parse as a genuine RSA
 * key of at least 2048 bits — smaller is considered broken by current
 * cryptographic guidance, not merely discouraged.
 *
 * Every assertion here takes the caller's own exception factory rather
 * than throwing a shared exception type, so each of the three callers
 * keeps its own package-appropriate, named exception, and so no message
 * here ever risks embedding the secret or key material itself — the
 * factory closure decides what to say, this class only decides whether
 * to say it.
 *
 * Both assertions are total, standalone-safe public methods: neither
 * assumes a caller already validated anything beforehand.
 * assertKeyMaterial() checks $algorithm and $role itself (an
 * unsupported algorithm, or a $role outside the literal 'public'/
 * 'private', both throw), rather than trusting that
 * assertSupportedAlgorithm() ran first or that $role was spelled
 * correctly — a caller of assertKeyMaterial() alone must never see a
 * silent false-valid result for an algorithm or role this class
 * doesn't actually recognize.
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
     * identically, so $role is ignored there, but is still required to
     * be one of the two recognized values regardless of algorithm, so a
     * typo'd role can never silently pass as though it meant something.
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
     * Resolves $material to an OpenSSLAsymmetricKey matching $role,
     * without ever routing an already-parsed key object through
     * openssl_pkey_get_public()/openssl_pkey_get_private() — confirmed
     * directly that calling the role-specific function on a key object
     * whose own role doesn't match (a private key object handed to the
     * public-role path, most concretely) emits a genuine E_WARNING
     * before returning false. A PHP error handler that converts
     * warnings to exceptions (a common, legitimate pattern) would let
     * that warning escape as an unrelated exception instead of this
     * class's own controlled failure — a real contract leak, not a
     * hypothetical one.
     *
     * Firebase\JWT\JWK::parseKeySet() itself hands back exactly this
     * shape — an already-parsed OpenSSLAsymmetricKey, not a PEM string —
     * for every RSA key in a JWKS (confirmed directly by reading its
     * source), so this case is the realistic, expected one for
     * JwtAuthMiddleware's own key map, not a rare edge case: a Key
     * built from a parsed JWKS is exactly what this method must handle
     * warning-free.
     *
     * openssl_pkey_get_details() introspects any key object safely
     * regardless of its own role — used here to determine whether an
     * already-parsed object genuinely carries a private component (the
     * "d" field, present only when it does), and that's compared
     * against $role directly, matching what the role-specific OpenSSL
     * functions would have rejected anyway for a string, just without
     * their warning side effect: a private-key object is still refused
     * for the 'public' role, and a public-only object for 'private'.
     */
    private static function resolveRsaKey(
        string|OpenSSLAsymmetricKey|OpenSSLCertificate $material,
        string $role,
    ): OpenSSLAsymmetricKey|false {
        if (is_string($material)) {
            return $role === 'private' ? openssl_pkey_get_private($material) : openssl_pkey_get_public($material);
        }

        if ($material instanceof OpenSSLCertificate) {
            // A certificate only ever carries a public key — extracting
            // it never risks the role-mismatch warning above, since
            // there's no "wrong role" a certificate could be holding.
            return $role === 'private' ? false : openssl_pkey_get_public($material);
        }

        $details = openssl_pkey_get_details($material);
        $isPrivateKeyObject = $details !== false && isset($details['rsa']['d']);

        return $isPrivateKeyObject === ($role === 'private') ? $material : false;
    }
}
