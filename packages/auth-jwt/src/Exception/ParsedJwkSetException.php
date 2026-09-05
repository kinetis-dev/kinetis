<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Exception;

use Kinetis\AuthJwt\JwtKeyValidator;
use RuntimeException;

/**
 * Every way ParsedJwkSet::fromJson() can refuse a JWKS document.
 *
 * A JWKS reaching that parser is untrusted input, so a message here
 * names the rule and the zero-based position of the offending key in
 * the document's own `keys` list, and no part of the input itself: not
 * the raw document, not a kid, not modulus or exponent bytes.
 *
 * No factory chains a previous exception. A key that will not compose
 * fails inside firebase/php-jwt or OpenSSL, which describe the failure
 * in terms of the key material they were handed; chaining that puts it
 * into the string form of every exception above it in the chain.
 */
final class ParsedJwkSetException extends RuntimeException
{
    public static function malformedDocument(): self
    {
        return new self(
            'The JWKS is not a well-formed JSON object within ParsedJwkSet\'s own size and nesting '
            . 'limits, or names the same member twice.',
        );
    }

    public static function missingKeysMember(): self
    {
        return new self('The JWKS has no "keys" member.');
    }

    public static function malformedKeysMember(): self
    {
        return new self('The JWKS "keys" member must be a non-empty JSON array.');
    }

    public static function tooManyKeys(int $maximum): self
    {
        return new self("The JWKS holds more than the {$maximum} keys ParsedJwkSet accepts.");
    }

    public static function keyNotAnObject(int $index): self
    {
        return new self("The JWKS key at index {$index} is not a non-empty JSON object.");
    }

    public static function symmetricKey(int $index): self
    {
        return new self(
            "The JWKS key at index {$index} declares kty \"oct\", whose material is the shared secret "
            . 'itself — a published key set must never carry one.',
        );
    }

    public static function unsupportedKeyType(int $index): self
    {
        return new self(
            "The JWKS key at index {$index} declares a kty ParsedJwkSet does not verify with — only "
            . '"RSA" is supported.',
        );
    }

    public static function privateKeyMaterial(int $index): self
    {
        return new self(
            "The JWKS key at index {$index} carries private or secret key material — a published key set "
            . 'holds public keys only.',
        );
    }

    public static function invalidKid(int $index): self
    {
        $maximum = JwtKeyValidator::MAXIMUM_KID_LENGTH;

        return new self(
            "The JWKS key at index {$index} must carry a kid: non-blank, valid UTF-8, and at most "
            . "{$maximum} bytes.",
        );
    }

    public static function duplicateKid(int $index): self
    {
        return new self(
            "The JWKS key at index {$index} repeats a kid an earlier key already claims — a token naming "
            . 'it would select no one key.',
        );
    }

    public static function unsupportedAlgorithm(int $index): self
    {
        return new self(
            "The JWKS key at index {$index} must declare an alg of RS256, RS384 or RS512 — the RSA "
            . 'algorithms this package verifies with.',
        );
    }

    public static function unsupportedKeyUse(int $index): self
    {
        return new self(
            "The JWKS key at index {$index} declares a \"use\" other than \"sig\" — its own publisher "
            . 'restricts it to something this package does not do with a key.',
        );
    }

    public static function unsupportedKeyOperations(int $index): self
    {
        return new self(
            "The JWKS key at index {$index} declares key_ops other than exactly [\"verify\"] — the one "
            . 'operation this package performs with a published key.',
        );
    }

    public static function invalidKeyField(int $index, string $field): self
    {
        return new self(
            "The JWKS key at index {$index} has a malformed \"{$field}\" — an RSA {$field} must be the "
            . 'canonical unpadded base64url spelling of an unsigned integer of the expected size.',
        );
    }

    public static function unusableKey(int $index): self
    {
        $minimumBits = JwtKeyValidator::RSA_MINIMUM_BITS;

        return new self(
            "The JWKS key at index {$index} does not compose into a usable RSA public key of at least "
            . "the {$minimumBits}-bit minimum this package verifies with.",
        );
    }
}
