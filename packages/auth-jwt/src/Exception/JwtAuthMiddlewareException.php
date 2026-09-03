<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Exception;

use Kinetis\AuthJwt\JwtKeyValidator;
use RuntimeException;

final class JwtAuthMiddlewareException extends RuntimeException
{
    /**
     * $expectedIssuer was the empty string rather than a real issuer
     * name or null — an empty string can never match a real token's own
     * `iss` claim (also rejected as malformed, see the class docblock),
     * so this constraint could never be satisfied by any token at all.
     */
    public static function emptyExpectedIssuer(): self
    {
        return new self(
            'JwtAuthMiddleware\'s $expectedIssuer must be a non-empty string, or null to accept any issuer '
            . '— an empty string would reject every token unconditionally.',
        );
    }

    /**
     * $acceptedAudiences was an empty array rather than a real,
     * non-empty list or null — an empty list can never contain a match,
     * so this constraint could never be satisfied by any token at all.
     */
    public static function emptyAcceptedAudiences(): self
    {
        return new self(
            'JwtAuthMiddleware\'s $acceptedAudiences must be a non-empty list of strings, or null to '
            . 'accept any audience — an empty list would reject every token unconditionally.',
        );
    }

    /**
     * $acceptedAudiences contained a non-string or empty-string entry —
     * that entry could never legitimately match any real audience claim.
     */
    public static function invalidAcceptedAudience(): self
    {
        return new self(
            'JwtAuthMiddleware\'s $acceptedAudiences must contain only non-empty strings.',
        );
    }

    /**
     * $acceptedAudiences was an array, but not a list (array_is_list()
     * false) — an associative or sparse-numeric array. Its own type is
     * documented as list<string>; silently accepting a differently-
     * shaped array at construction would contradict that contract even
     * though in_array()/array_intersect() happen to work on any array
     * regardless of its keys.
     */
    public static function acceptedAudiencesNotAList(): self
    {
        return new self(
            'JwtAuthMiddleware\'s $acceptedAudiences must be a list (sequential integer keys starting at '
            . '0) — an associative or sparse array doesn\'t match its documented list<string> shape.',
        );
    }

    /**
     * $algorithm isn't one of the six this package supports — the
     * single-key ($key: string) form only; a key map validates each
     * Key's own algorithm instead (see unsupportedKeyMapAlgorithm()).
     */
    public static function unsupportedAlgorithm(string $algorithm): self
    {
        $supported = implode(', ', JwtKeyValidator::SUPPORTED_ALGORITHMS);

        return new self(
            "JwtAuthMiddleware's \$algorithm \"{$algorithm}\" is not supported — must be one of: {$supported}.",
        );
    }

    /**
     * The single-key ($key: string) form's $key is too short for
     * $algorithm's own HMAC digest size — never names the key itself.
     */
    public static function hmacSecretTooShort(string $algorithm): self
    {
        return new self(
            "JwtAuthMiddleware's \$key is too short for {$algorithm}: RFC 7518 §3.2 requires an HMAC "
            . 'secret at least as long, in bytes, as the algorithm\'s own digest output.',
        );
    }

    /**
     * The single-key ($key: string) form's $key doesn't parse as a
     * genuine RSA public key of at least 2048 bits for an RS256/RS384/
     * RS512 $algorithm — never names the key material itself.
     */
    public static function invalidRsaPublicKey(): self
    {
        return new self(
            "JwtAuthMiddleware's \$key must be a PEM-format RSA public key of at least 2048 bits for an "
            . 'RS256/RS384/RS512 $algorithm — construction found it unparseable, not RSA, or undersized.',
        );
    }

    /**
     * $key was given as an array but with nothing in it — a middleware
     * that can never verify any token, since no kid could ever match.
     */
    public static function emptyKeyMap(): self
    {
        return new self(
            'JwtAuthMiddleware\'s $key, when given as an array, must contain at least one entry — an '
            . 'empty map can never verify any token.',
        );
    }

    /**
     * A $key map entry's own key (kid) isn't a non-empty string — either
     * a non-string array key (PHP allows an int) or an empty string.
     */
    public static function invalidKeyMapKid(): self
    {
        return new self(
            "JwtAuthMiddleware's \$key map keys (kid) must each be a non-empty string.",
        );
    }

    /**
     * A $key map entry's value isn't a Firebase\JWT\Key instance —
     * names the offending kid, not secret material.
     */
    public static function invalidKeyMapValue(string $kid): self
    {
        return new self(
            "JwtAuthMiddleware's \$key map entry for kid \"{$kid}\" must be a Firebase\\JWT\\Key instance.",
        );
    }

    /**
     * A $key map entry's own Key::getAlgorithm() isn't one of the six
     * this package supports — names the offending kid and the supported
     * set, not secret material.
     */
    public static function unsupportedKeyMapAlgorithm(string $kid): self
    {
        $supported = implode(', ', JwtKeyValidator::SUPPORTED_ALGORITHMS);

        return new self(
            "JwtAuthMiddleware's \$key map entry for kid \"{$kid}\" has an unsupported algorithm — must "
            . "be one of: {$supported}.",
        );
    }

    /**
     * A $key map entry's own Key::getKeyMaterial() doesn't fit its
     * declared Key::getAlgorithm() (too-short HMAC secret, or an
     * unparseable/non-RSA/undersized RSA public key) — names the
     * offending kid, never the key material itself.
     */
    public static function invalidKeyMapKeyMaterial(string $kid): self
    {
        return new self(
            "JwtAuthMiddleware's \$key map entry for kid \"{$kid}\" has key material that doesn't fit "
            . 'its own declared algorithm — an HMAC secret shorter than the algorithm\'s digest output, '
            . 'or an unparseable/non-RSA/undersized (below 2048 bits) RSA public key.',
        );
    }
}
