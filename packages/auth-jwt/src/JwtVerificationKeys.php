<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt;

use DomainException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use stdClass;
use UnexpectedValueException;

/**
 * The keys JwtAuthenticator verifies against, and the one place a
 * verification algorithm and the set of selectable `kid`s are named.
 * Immutable, holds no request state, and validates everything at
 * construction — never on the first request.
 *
 * hmacSecret() takes the same shared secret JwtSigningKey::hmacSecret()
 * signs with. rsaPublicKey() takes the *public* half of an RSA key pair
 * as a PEM string; handing it a private key is rejected.
 *
 * jwks() is the multi-key form: an RFC 7517 JWK Set document, parsed by
 * JwkSetParser, holding one RSA public key per kid. A token's own
 * (unverified) kid header selects which key verifies it, matched as the
 * exact string the document published, so a signing key can be rolled
 * over while tokens issued under the previous one keep verifying. A
 * deployment holding PEM files publishes the same document through
 * JwkSet.
 *
 * decode() answers null for every unusable token — the caller's whole
 * vocabulary for one is a generic 401.
 */
final readonly class JwtVerificationKeys
{
    /**
     * Exactly one of the two is set: $singleKey for the forms that name
     * one key, $keysByLookupKey for a parsed JWK Set.
     *
     * @param array<string, Key> $keysByLookupKey keyed by JwkSetParser::LOOKUP_PREFIX . kid
     */
    private function __construct(
        private ?Key $singleKey,
        private array $keysByLookupKey,
    ) {}

    public static function hmacSecret(#[\SensitiveParameter] string $secret, string $algorithm = 'HS256'): self
    {
        JwtKeyValidator::assertHmacSecret($algorithm, $secret);

        return new self(new Key($secret, $algorithm), []);
    }

    public static function rsaPublicKey(string $publicKeyPem, string $algorithm = 'RS256'): self
    {
        JwtKeyValidator::assertRsaPublicKey($algorithm, $publicKeyPem);

        return new self(new Key($publicKeyPem, $algorithm), []);
    }

    public static function jwks(#[\SensitiveParameter] string $jwksJson): self
    {
        return new self(null, JwkSetParser::parse($jwksJson));
    }

    /**
     * Whether a token must carry a `kid` this set knows to reach
     * verification at all.
     */
    public function requiresKid(): bool
    {
        return $this->singleKey === null;
    }

    /**
     * Verifies $token against the key $kid selects and returns its
     * claims, or null when no key matches or the token does not verify.
     *
     * Resolving the key here, rather than leaving the lookup to
     * JWT::decode(), keeps an unknown kid a rejection this package made,
     * with no PHP array key between the token's kid and the key it
     * selects. The resolved key still pins the algorithm: JWT::decode()
     * refuses a token whose header names anything but that key's own.
     */
    public function decode(#[\SensitiveParameter] string $token, ?string $kid): ?stdClass
    {
        $key = $this->keyFor($kid);

        if ($key === null) {
            return null;
        }

        try {
            return JWT::decode($token, $key);
        } catch (UnexpectedValueException|DomainException) {
            return null;
        }
    }

    private function keyFor(?string $kid): ?Key
    {
        if ($this->singleKey !== null) {
            return $this->singleKey;
        }

        if ($kid === null) {
            return null;
        }

        return $this->keysByLookupKey[JwkSetParser::LOOKUP_PREFIX . $kid] ?? null;
    }
}
