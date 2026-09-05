<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt;

use Kinetis\AuthJwt\Exception\JwkSetException;

/**
 * One RSA public key and the kid it is published under — the input
 * JwkSet builds a JWK Set from.
 *
 * The kid is a string property rather than a position in a
 * `kid => PEM` map because a PHP array key cannot hold every kid this
 * package supports; ParsedJwkSet documents why, and carrying the kid as
 * a value is what lets JwkSet publish exactly the kids that class reads
 * back.
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
            throw JwkSetException::invalidKid($kid);
        }
    }
}
