<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt;

use Kinetis\AuthJwt\Exception\JwtConfigurationException;

/**
 * One RSA public key and the kid it is published under — the input
 * JwkSet builds a JWK Set from.
 *
 * The kid is a string property rather than a position in a
 * `kid => PEM` map because a PHP array key cannot hold every kid this
 * package supports: `'0'` used as one becomes the integer 0. Carrying
 * the kid as a value is what lets JwkSet publish exactly the kids
 * JwkSetParser reads back.
 *
 * $kid is held to JwtKeyValidator::isUsableKid(). $publicKey is a
 * PEM-format RSA public key, parsed by JwkSet rather than here, since
 * whether a key fits depends on the algorithm it is published under.
 */
final class PublishedRsaKey
{
    public function __construct(
        public private(set) string $kid,
        public private(set) string $publicKey,
    ) {
        if (!JwtKeyValidator::isUsableKid($kid)) {
            // Rendered only when it is valid UTF-8, so a message never
            // carries bytes nothing downstream could encode.
            $shown = preg_match('//u', $kid) === 1 ? var_export($kid, true) : 'bytes that are not valid UTF-8';

            throw JwtConfigurationException::invalidKid(
                'a published key\'s kid must be non-blank, valid UTF-8, and at most '
                . JwtKeyValidator::MAXIMUM_KID_LENGTH . " bytes — got {$shown}",
            );
        }
    }
}
