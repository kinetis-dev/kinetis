<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt;

use Firebase\JWT\JWT;
use Kinetis\AuthJwt\Exception\JwtIssuerException;

/**
 * Stateless by design — no storage, since that's the entire point of a
 * JWT. Signs claims with the key JwtAuthMiddleware verifies against — the
 * *same* key for a symmetric algorithm (`HS256`/`HS384`/`HS512`), or the
 * *private* half of a key pair for an asymmetric one (`RS256`/`RS384`/
 * `RS512`), passed as a PEM-format string; JwtAuthMiddleware takes the
 * public half in that case. Issuing a token to a user (verifying a
 * password, calling this, returning the result to the client) is your own
 * login endpoint's job; this only covers "given a subject, produce a
 * signed token."
 *
 * $algorithm and $key are validated at construction, via
 * JwtKeyValidator — never on the first issue() call. $algorithm must be
 * one of the six this package supports; $key must fit it (an HMAC
 * secret at least as long as the algorithm's digest, or a parseable RSA
 * private key of at least 2048 bits). A misconfigured issuer throws
 * immediately, naming what's wrong but never the key material itself,
 * rather than producing a token whose signature quietly can't be
 * trusted, or throwing an unrelated OpenSSL/library error from inside
 * the first real issue() call.
 *
 * $issuer/$audience stamp fixed `iss`/`aud` claims on every token this
 * instance issues — the trusted-configuration side of
 * JwtAuthMiddleware's own $expectedIssuer/$acceptedAudiences. Deliberately
 * not settable through $claims: a value an application could override
 * per call wouldn't be trustworthy configuration, the same reasoning
 * `sub`/`iat`/`jti`/`exp` already follow. $audience accepts either a
 * single string or a list, matching `aud`'s own JWT-standard
 * flexibility — a token intended for more than one service audience at
 * once. Neither is set unless configured; a JwtIssuer built with both
 * left `null` (the default) never writes `iss`/`aud` at all, exactly as
 * before either existed.
 *
 * $kid, when given, is written into the token's own header — pair it
 * with JwtAuthMiddleware's own multi-key `$key` support to roll a
 * signing key over without invalidating every token issued under the
 * previous one: publish both keys, each under its own kid, during the
 * overlap window. Must be `null` (no `kid` header at all) or a
 * non-empty string — an empty string throws at construction, since
 * neither JwtAuthMiddleware's key-map form nor JwkSet can represent or
 * select an empty kid either, and a token issued with one could never
 * be verified through either.
 */
final readonly class JwtIssuer
{
    /**
     * A given array $audience must be list-shaped and every element a
     * non-empty string — checked below, never declared here as
     * array<string> or list<string>, since this constructor's own body
     * is what establishes that stronger guarantee for a caller;
     * declaring it already-true on entry would make the validation that
     * enforces it look like unreachable code. mixed is a real, if
     * unhelpful, answer to PHPStan's own "specify the array's value
     * type" requirement — deliberately not a more specific one.
     *
     * @param string|array<mixed>|null $audience
     */
    public function __construct(
        private string $key,
        private string $algorithm = 'HS256',
        private ?string $kid = null,
        private ?string $issuer = null,
        private string|array|null $audience = null,
    ) {
        JwtKeyValidator::assertSupportedAlgorithm(
            $algorithm,
            static fn () => JwtIssuerException::unsupportedAlgorithm($algorithm),
        );

        JwtKeyValidator::assertKeyMaterial(
            $algorithm,
            $key,
            'private',
            static fn () => JwtKeyValidator::isHmacAlgorithm($algorithm)
                ? JwtIssuerException::hmacSecretTooShort($algorithm)
                : JwtIssuerException::invalidRsaPrivateKey(),
        );

        if ($kid === '') {
            throw JwtIssuerException::emptyKid();
        }

        if ($issuer === '') {
            throw JwtIssuerException::emptyIssuer();
        }

        if (is_string($audience) && $audience === '') {
            throw JwtIssuerException::emptyAudience();
        }

        if (is_array($audience)) {
            if ($audience === []) {
                throw JwtIssuerException::emptyAudience();
            }

            if (!array_is_list($audience)) {
                throw JwtIssuerException::audienceNotAList();
            }

            foreach ($audience as $value) {
                if (!is_string($value) || $value === '') {
                    throw JwtIssuerException::invalidAudienceElement();
                }
            }
        }
    }

    /**
     * $ttlSeconds is null for a token with no `exp` claim at all — a
     * genuinely non-expiring token, not a stand-in for "a very long
     * lifetime" — or a positive number of seconds until expiry; zero or
     * negative is rejected outright, since it would produce a token
     * that's already expired or expires before it could ever be used.
     * A $ttlSeconds large enough that `time() + $ttlSeconds` would
     * overflow this platform's integer range is rejected the same way,
     * rather than silently letting PHP promote the sum to a float and
     * corrupt the resulting `exp` claim.
     *
     * @param array<string, mixed> $claims extra claims merged in alongside `sub`/`iat`/`exp`/`jti` (and `iss`/`aud`, when configured), which always win if duplicated
     */
    public function issue(string|int $subject, array $claims = [], ?int $ttlSeconds = 3600): string
    {
        $now = time();
        $payload = [...$claims, 'sub' => (string) $subject, 'iat' => $now, 'jti' => bin2hex(random_bytes(16))];

        if ($this->issuer !== null) {
            $payload['iss'] = $this->issuer;
        }

        if ($this->audience !== null) {
            $payload['aud'] = $this->audience;
        }

        if ($ttlSeconds !== null) {
            if ($ttlSeconds <= 0) {
                throw JwtIssuerException::nonPositiveTtl();
            }

            if ($ttlSeconds > PHP_INT_MAX - $now) {
                throw JwtIssuerException::ttlOverflow();
            }

            $payload['exp'] = $now + $ttlSeconds;
        }

        return JWT::encode($payload, $this->key, $this->algorithm, $this->kid);
    }
}
