<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Exception;

use Kinetis\AuthJwt\JwtKeyValidator;
use RuntimeException;

/**
 * Every way PublishedRsaKey and JwkSet::fromRsaPublicKeys() can refuse
 * to publish a JWK Set.
 *
 * A kid is named here where one is known. Unlike a kid arriving in a
 * fetched document (see ParsedJwkSetException), a kid on this side is
 * an application's own configuration, and naming it is what makes the
 * failure actionable; key material itself is never rendered.
 */
final class JwkSetException extends RuntimeException
{
    /**
     * $keys held nothing, so the JWKS would carry no keys at all.
     */
    public static function emptyKeyList(): self
    {
        return new self(
            'JwkSet::fromRsaPublicKeys() requires at least one PublishedRsaKey — publishing an empty '
            . '"keys" array advertises a key set nothing can verify against.',
        );
    }

    /**
     * $keys is an array but not a list (array_is_list() false). Its
     * documented type is list<PublishedRsaKey>; a map keyed by kid
     * would look correct while those keys went unread, so the shape is
     * enforced rather than tolerated.
     */
    public static function keysNotAList(): self
    {
        return new self(
            'JwkSet::fromRsaPublicKeys()\'s $keys must be a list (sequential integer keys starting at 0) '
            . 'of PublishedRsaKey values — an associative or sparse array does not match that shape.',
        );
    }

    public static function notAPublishedKey(): self
    {
        return new self(
            'JwkSet::fromRsaPublicKeys() accepts only PublishedRsaKey values — a kid belongs in one of '
            . 'those, not in a PHP array key.',
        );
    }

    /**
     * A kid is outside JwtKeyValidator::isUsableKid(). The offending
     * value is rendered only when it is valid UTF-8, so a message never
     * carries bytes nothing downstream could encode.
     */
    public static function invalidKid(string $kid): self
    {
        $maximum = JwtKeyValidator::MAXIMUM_KID_LENGTH;
        $shown = preg_match('//u', $kid) === 1 ? var_export($kid, true) : 'bytes that are not valid UTF-8';

        return new self(
            "A PublishedRsaKey's kid must be non-blank, valid UTF-8, and at most {$maximum} bytes — "
            . "got {$shown}.",
        );
    }

    /**
     * Two entries claim one kid, so a token naming it would select
     * neither key in particular.
     */
    public static function duplicateKid(string $kid): self
    {
        return new self(
            "JwkSet::fromRsaPublicKeys() was given more than one key under the kid \"{$kid}\" — a token "
            . 'naming it would select no one key.',
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
     * The public key given for $kid parses as RSA but is smaller than
     * JwtKeyValidator::RSA_MINIMUM_BITS, so publishing it would
     * advertise a key every verifier using that rule refuses.
     */
    public static function undersizedRsaKey(string $kid): self
    {
        $minimumBits = JwtKeyValidator::RSA_MINIMUM_BITS;

        return new self(
            "The public key given for kid \"{$kid}\" is a valid RSA key but smaller than the required "
            . "{$minimumBits}-bit minimum.",
        );
    }
}
