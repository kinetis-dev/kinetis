<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt;

use Kinetis\AuthJwt\Exception\JwkSetException;

/**
 * Builds an RFC 7517 JWK Set from one or more PublishedRsaKey values —
 * the publishing half of JwtAuthMiddleware's multi-key rotation
 * support, whose consuming half is ParsedJwkSet. RSA only: an HS256 key
 * is symmetric and is never published, and this doesn't cover other
 * asymmetric key types.
 *
 * Every input is validated before any output is produced, so a
 * published document can never advertise a key or algorithm this
 * package's own verifier refuses. Failures throw JwkSetException, which
 * states each rule; each kid arrives already held to the shared rule by
 * PublishedRsaKey.
 *
 * Returns a plain array, not a JSON string — a route method returning
 * it is JSON-encoded automatically the same way any other Kinetis
 * route return value is. Not registered anywhere automatically; a
 * consumer's own controller calls this the same way any other
 * issuance-adjacent endpoint in this package is application-owned.
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
            throw JwkSetException::unsupportedAlgorithm($algorithm);
        }

        if ($keys === []) {
            throw JwkSetException::emptyKeyList();
        }

        if (!array_is_list($keys)) {
            throw JwkSetException::keysNotAList();
        }

        $jwks = [];
        // Compared as strings rather than gathered as array keys, for
        // the same reason PublishedRsaKey carries its kid as a value.
        $claimedKids = [];

        foreach ($keys as $key) {
            if (!$key instanceof PublishedRsaKey) {
                throw JwkSetException::notAPublishedKey();
            }

            if (in_array($key->kid, $claimedKids, true)) {
                throw JwkSetException::duplicateKid($key->kid);
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

        if ($parsed === false) {
            throw JwkSetException::invalidPublicKey($key->kid);
        }

        $details = openssl_pkey_get_details($parsed);

        if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_RSA) {
            throw JwkSetException::notAnRsaKey($key->kid);
        }

        if ($details['bits'] < JwtKeyValidator::RSA_MINIMUM_BITS) {
            throw JwkSetException::undersizedRsaKey($key->kid);
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
