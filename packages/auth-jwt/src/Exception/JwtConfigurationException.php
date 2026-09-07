<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Exception;

use RuntimeException;

/**
 * Every way this package refuses the cryptographic configuration it is
 * handed: an algorithm outside the supported six, key material that
 * does not fit its algorithm and role, a kid no verifier could select,
 * an issuer/audience constraint no token could satisfy, and a JWK Set
 * this package will not read or publish.
 *
 * A message names the rule and, for a JWK Set, the zero-based position
 * of the offending key. It never carries key material, a secret, or the
 * document itself, and it chains no OpenSSL or firebase/php-jwt cause:
 * those describe a failure in terms of the material they were handed.
 */
final class JwtConfigurationException extends RuntimeException
{
    public static function unsupportedAlgorithm(string $algorithm, string $supported): self
    {
        return new self("Algorithm \"{$algorithm}\" is not supported here — must be one of: {$supported}.");
    }

    public static function unusableKeyMaterial(string $detail): self
    {
        return new self("Unusable JWT key material: {$detail}.");
    }

    public static function invalidKid(string $detail): self
    {
        return new self("Unusable JWT key id: {$detail}.");
    }

    public static function invalidClaimConstraint(string $detail): self
    {
        return new self("Unusable JWT claim constraint: {$detail}.");
    }

    public static function invalidJwkSet(string $detail): self
    {
        return new self("Unusable JWK Set: {$detail}.");
    }
}
