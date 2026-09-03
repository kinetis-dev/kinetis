<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Exception;

use RuntimeException;

final class JwkSetException extends RuntimeException
{
    /**
     * $publicKeysByKid was empty — a JWKS with no keys can never verify
     * anything, so this is rejected outright rather than producing a
     * syntactically valid but useless {"keys": []}.
     */
    public static function emptyKeySet(): self
    {
        return new self(
            'JwkSet::fromRsaPublicKeys() requires at least one key — an empty map would produce a '
            . 'JWKS that can never verify anything.',
        );
    }

    /**
     * A $publicKeysByKid array key (kid) isn't a non-empty string —
     * either a non-string array key (PHP allows an int) or an empty
     * string. $kid is included when it's safe to render (a scalar);
     * kid is a public identifier, never secret.
     */
    public static function invalidKid(mixed $kid): self
    {
        $shown = is_scalar($kid) ? var_export($kid, true) : gettype($kid);

        return new self(
            "JwkSet::fromRsaPublicKeys()'s keys (kid) must each be a non-empty string — got {$shown}.",
        );
    }

    /**
     * A $publicKeysByKid value isn't a string — never renders the value
     * itself, only that it's the wrong type.
     */
    public static function invalidPublicKeyType(string $kid): self
    {
        return new self(
            "JwkSet::fromRsaPublicKeys()'s value for kid \"{$kid}\" must be a PEM-format string.",
        );
    }

    /**
     * $algorithm isn't RS256/RS384/RS512 — publishing kty: RSA under an
     * unsupported or non-RSA algorithm (HS256, nonsense, ...) would be a
     * JWKS whose own fields contradict each other.
     */
    public static function unsupportedAlgorithm(string $algorithm): self
    {
        return new self(
            "JwkSet::fromRsaPublicKeys()'s \$algorithm \"{$algorithm}\" is not supported — must be one "
            . 'of: RS256, RS384, RS512.',
        );
    }

    public static function invalidPublicKey(string $kid): self
    {
        return new self("The public key given for kid \"{$kid}\" is not a valid PEM-format key.");
    }

    public static function notAnRsaKey(string $kid): self
    {
        return new self("The public key given for kid \"{$kid}\" is not an RSA key — JwkSet only supports RSA (RS256/RS384/RS512).");
    }

    /**
     * The public key given for $kid is a genuine RSA key, but smaller
     * than the 2048-bit minimum — publishing it would advertise a key
     * every verifier using JwtKeyValidator's own rule will refuse.
     */
    public static function undersizedRsaKey(string $kid): self
    {
        return new self(
            "The public key given for kid \"{$kid}\" is a valid RSA key but smaller than the required "
            . '2048-bit minimum.',
        );
    }
}
