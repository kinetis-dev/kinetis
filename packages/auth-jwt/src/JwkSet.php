<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt;

use Kinetis\AuthJwt\Exception\JwtConfigurationException;

/**
 * Builds an RFC 7517 JWK Set from one or more PublishedRsaKey values —
 * the publishing half of JwtVerificationKeys::jwks(), whose consuming
 * half is JwkSetParser. RSA only: an HS256 key is symmetric and is never
 * published, and this does not cover other asymmetric key types.
 *
 * Every input is validated before any output is produced, so a published
 * document can never advertise a key or algorithm this package's own
 * verifier refuses.
 *
 * Returns a plain array, not a JSON string — a route method returning it
 * is JSON-encoded automatically the same way any other Kinetis route
 * return value is. Nothing registers such a route; a consumer's own
 * controller calls this.
 */
final class JwkSet
{
    /**
     * @param list<PublishedRsaKey> $keys the documented, correct-caller shape; runtime validation below still checks it, since a caller can hand in something else regardless of what static tooling expects
     * @return array{keys: list<array{kty: string, kid: string, use: string, alg: string, n: string, e: string}>}
     */
    public static function fromRsaPublicKeys(array $keys, string $algorithm = 'RS256'): array
    {
        if (!JwtKeyValidator::isRsaAlgorithm($algorithm)) {
            throw JwtConfigurationException::unsupportedAlgorithm($algorithm, 'RS256, RS384, RS512');
        }

        if ($keys === [] || !array_is_list($keys)) {
            throw JwtConfigurationException::invalidJwkSet(
                'publishing takes a non-empty list of PublishedRsaKey values',
            );
        }

        $jwks = [];
        // Compared as strings rather than gathered as array keys, for
        // the same reason PublishedRsaKey carries its kid as a value.
        $claimedKids = [];

        foreach ($keys as $key) {
            if (!$key instanceof PublishedRsaKey) {
                throw JwtConfigurationException::invalidJwkSet(
                    'publishing accepts only PublishedRsaKey values — a kid belongs in one of those, not '
                    . 'in a PHP array key',
                );
            }

            if (in_array($key->kid, $claimedKids, true)) {
                throw JwtConfigurationException::invalidJwkSet(
                    "more than one key is published under the kid \"{$key->kid}\" — a token naming it "
                    . 'would select no one key',
                );
            }

            $claimedKids[] = $key->kid;
            $jwks[] = self::jwkFor($key, $algorithm);
        }

        return ['keys' => $jwks];
    }

    /**
     * @return array{kty: string, kid: string, use: string, alg: string, n: string, e: string}
     */
    private static function jwkFor(PublishedRsaKey $key, string $algorithm): array
    {
        $parsed = openssl_pkey_get_public($key->publicKey);
        $details = $parsed === false ? false : openssl_pkey_get_details($parsed);

        // The same rule JwtKeyValidator::assertRsaPublicKey() enforces,
        // applied here because publishing needs the parsed modulus and
        // exponent anyway, and names the offending kid: on this side a
        // kid is the application's own configuration, and naming it is
        // what makes the failure actionable.
        if (
            $details === false
            || $details['type'] !== OPENSSL_KEYTYPE_RSA
            || $details['bits'] < JwtKeyValidator::RSA_MINIMUM_BITS
        ) {
            throw JwtConfigurationException::invalidJwkSet(
                "the key published under \"{$key->kid}\" must be a PEM-format RSA public key of at least "
                . JwtKeyValidator::RSA_MINIMUM_BITS . ' bits',
            );
        }

        return [
            'kty' => 'RSA',
            'kid' => $key->kid,
            'use' => 'sig',
            'alg' => $algorithm,
            'n' => Base64Url::encode($details['rsa']['n']),
            'e' => Base64Url::encode($details['rsa']['e']),
        ];
    }
}
