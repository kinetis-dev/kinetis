<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt;

use Kinetis\AuthJwt\Exception\JwkSetException;

/**
 * Builds an RFC 7517 JWK Set from one or more RSA public keys, each
 * under its own kid — the publishing half of JwtAuthMiddleware's
 * multi-key rotation support. RSA only: an HS256 key is symmetric and
 * is never published, and this doesn't cover other asymmetric key
 * types.
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
     * @param array<string, string> $publicKeysByKid PEM-format RSA public keys, keyed by their own kid
     * @return array{keys: list<array{kty: string, kid: string, use: string, alg: string, n: string, e: string}>}
     */
    public static function fromRsaPublicKeys(array $publicKeysByKid, string $algorithm = 'RS256'): array
    {
        $keys = [];

        foreach ($publicKeysByKid as $kid => $pem) {
            $keys[] = self::jwkFor($kid, $pem, $algorithm);
        }

        return ['keys' => $keys];
    }

    /**
     * @return array{kty: string, kid: string, use: string, alg: string, n: string, e: string}
     */
    private static function jwkFor(string $kid, string $pem, string $algorithm): array
    {
        $key = openssl_pkey_get_public($pem);

        if ($key === false) {
            throw JwkSetException::invalidPublicKey($kid);
        }

        $details = openssl_pkey_get_details($key);

        if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_RSA) {
            throw JwkSetException::notAnRsaKey($kid);
        }

        return [
            'kty' => 'RSA',
            'kid' => $kid,
            'use' => 'sig',
            'alg' => $algorithm,
            'n' => self::base64UrlEncode($details['rsa']['n']),
            'e' => self::base64UrlEncode($details['rsa']['e']),
        ];
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
