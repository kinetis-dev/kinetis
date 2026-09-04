<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Exception;

use Kinetis\AuthJwt\JwtKeyValidator;
use RuntimeException;

final class JwtIssuerException extends RuntimeException
{
    public static function nonPositiveTtl(): self
    {
        return new self(
            'JwtIssuer::issue() requires a positive $ttlSeconds, or null for a token with no expiry at '
            . 'all — a value of zero or less would produce a token that is already expired, or expires '
            . 'before it could ever be used.',
        );
    }

    public static function ttlOverflow(): self
    {
        return new self(
            'JwtIssuer::issue()\'s $ttlSeconds is too large: adding it to the current time would overflow '
            . "this platform's integer range, producing a corrupted \"exp\" claim rather than a real future "
            . 'timestamp. Use a smaller TTL.',
        );
    }

    /**
     * $kid is outside JwtKeyValidator::isUsableKid() — blank, or longer
     * than a kid may be. Stamping one would issue a token no rotation
     * or JWKS configuration this package supports could select.
     */
    public static function invalidKid(): self
    {
        $maximum = JwtKeyValidator::MAXIMUM_KID_LENGTH;

        return new self(
            "JwtIssuer's \$kid must be non-blank, valid UTF-8, and at most {$maximum} bytes, or null to "
            . 'omit the "kid" header entirely — anything else names a key no verifier could match.',
        );
    }

    /**
     * $issuer was the empty string rather than a real issuer name or
     * null — an empty string can never match JwtAuthMiddleware's own
     * $expectedIssuer check, which also rejects an empty `iss` claim as
     * malformed.
     */
    public static function emptyIssuer(): self
    {
        return new self(
            'JwtIssuer\'s $issuer must be a non-empty string, or null to omit the "iss" claim entirely — '
            . 'an empty string would never match any real issuer check.',
        );
    }

    /**
     * $audience was an empty string, or an empty array, rather than a
     * real audience value or null.
     */
    public static function emptyAudience(): self
    {
        return new self(
            'JwtIssuer\'s $audience must be a non-empty string or a non-empty list of strings, or null to '
            . 'omit the "aud" claim entirely — an empty value would never match any real audience check.',
        );
    }

    /**
     * $audience was an array containing a non-string or empty-string
     * entry.
     */
    public static function invalidAudienceElement(): self
    {
        return new self(
            'JwtIssuer\'s $audience, when given as an array, must contain only non-empty strings.',
        );
    }

    /**
     * $audience was an array, but not a list (array_is_list() false) —
     * an associative or sparse-numeric array. json_encode() serializes
     * that shape as a JSON object, not the JWT standard's array-of-
     * strings form, so the resulting "aud" claim would decode on the
     * verifying side as a JSON object rather than a string or array —
     * a token this same class's own construction accepted would then
     * fail every verifier's audience check, including one configured
     * with an exactly matching value.
     */
    public static function audienceNotAList(): self
    {
        return new self(
            'JwtIssuer\'s $audience, when given as an array, must be a list (sequential integer keys '
            . 'starting at 0) — an associative or sparse array serializes as a JSON object, not the JWT '
            . 'standard\'s array-of-strings "aud" form, and would fail every verifier\'s audience check.',
        );
    }

    /**
     * $algorithm isn't one of the six this package supports. Names the
     * given algorithm and the supported set — neither is secret, and
     * both are what makes this message actionable.
     */
    public static function unsupportedAlgorithm(string $algorithm): self
    {
        $supported = implode(', ', JwtKeyValidator::SUPPORTED_ALGORITHMS);

        return new self(
            "JwtIssuer's \$algorithm \"{$algorithm}\" is not supported — must be one of: {$supported}.",
        );
    }

    /**
     * $key is too short for $algorithm's own HMAC digest size — never
     * names the key itself, only the requirement.
     */
    public static function hmacSecretTooShort(string $algorithm): self
    {
        return new self(
            "JwtIssuer's \$key is too short for {$algorithm}: RFC 7518 §3.2 requires an HMAC secret at "
            . 'least as long, in bytes, as the algorithm\'s own digest output. A short secret is broken '
            . 'security, not merely discouraged — generate a real one, e.g. via random_bytes().',
        );
    }

    /**
     * $key doesn't parse as a genuine RSA private key of at least 2048
     * bits for an RS256/RS384/RS512 $algorithm — never names the key
     * material itself.
     */
    public static function invalidRsaPrivateKey(): self
    {
        return new self(
            "JwtIssuer's \$key must be a PEM-format RSA private key of at least 2048 bits for an "
            . 'RS256/RS384/RS512 $algorithm — construction found it unparseable, not RSA, or undersized.',
        );
    }
}
