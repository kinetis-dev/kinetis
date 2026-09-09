<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt;

use Kinetis\AuthJwt\Exception\JwtConfigurationException;
use Kinetis\AuthJwt\Exception\JwtIssuerException;

/**
 * Stateless by design — no storage, since that is the entire point of a
 * JWT. Signs claims with a JwtSigningKey, which owns the algorithm and
 * the `kid` header and validated both when it was built. Issuing a token
 * to a user (verifying a password, calling this, returning the result to
 * the client) is your own login endpoint's job; this only covers "given
 * a subject, produce a signed token."
 *
 * $issuer/$audience stamp fixed `iss`/`aud` claims on every token this
 * instance issues — the trusted-configuration side of
 * JwtAuthenticator's own $expectedIssuer/$acceptedAudiences.
 * Deliberately not settable through $claims: a value an application
 * could override per call would not be trustworthy configuration, the
 * same reasoning `sub`/`iat`/`jti`/`exp` already follow. $audience
 * accepts either a single string or a list, matching `aud`'s own
 * JWT-standard flexibility. Neither is written unless configured.
 *
 * A subject is one canonical non-empty string: issue() accepts
 * `string|int` so an application keying its users by integer id can hand
 * one straight over, and converts it to that string once, here, before
 * anything else in this package sees it. The `sub` claim, JwtUser::id(),
 * and RefreshTokenStore's own stored subject all carry that identical
 * string, so an access token and the refresh token issued beside it can
 * never name the subject two different ways.
 */
final readonly class JwtIssuer
{
    /**
     * A given array $audience must be list-shaped and every element a
     * non-empty string — checked below, never declared here as
     * list<string>, since this constructor's own body is what
     * establishes that guarantee for a caller.
     *
     * @param string|array<mixed>|null $audience
     */
    public function __construct(
        #[\SensitiveParameter] private JwtSigningKey $key,
        private ?string $issuer = null,
        private string|array|null $audience = null,
    ) {
        self::assertValidIssuerAndAudience($issuer, $audience);
    }

    /**
     * $ttlSeconds is null for a token with no `exp` claim at all — a
     * genuinely non-expiring token, not a stand-in for "a very long
     * lifetime" — or a positive number of seconds until expiry. Zero,
     * negative, and a value large enough that `time() + $ttlSeconds`
     * would overflow this platform's integer range are all rejected.
     *
     * @param array<string, mixed> $claims extra claims merged in alongside `sub`/`iat`/`exp`/`jti` (and `iss`/`aud`, when configured), which always win if duplicated
     */
    public function issue(
        string|int $subject,
        #[\SensitiveParameter] array $claims = [],
        ?int $ttlSeconds = 3600,
    ): string {
        $sub = (string) $subject;

        if ($sub === '') {
            throw JwtIssuerException::emptySubject();
        }

        $now = time();
        $payload = [...$claims, 'sub' => $sub, 'iat' => $now, 'jti' => bin2hex(random_bytes(16))];

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

        return $this->key->sign($payload);
    }

    /**
     * @param string|array<mixed>|null $audience
     */
    private static function assertValidIssuerAndAudience(?string $issuer, string|array|null $audience): void
    {
        if ($issuer === '') {
            throw JwtConfigurationException::invalidClaimConstraint(
                'an issuer must be a non-empty string, or null to omit the "iss" claim',
            );
        }

        if ($audience === null) {
            return;
        }

        if (is_string($audience)) {
            if ($audience === '') {
                throw JwtConfigurationException::invalidClaimConstraint(
                    'an audience must be a non-empty string, or null to omit the "aud" claim',
                );
            }

            return;
        }

        // An associative or sparse array serializes as a JSON object,
        // not the JWT standard's array-of-strings "aud" form, so every
        // verifier's audience check would fail against a token this
        // issuer accepted.
        if ($audience === [] || !array_is_list($audience)) {
            throw JwtConfigurationException::invalidClaimConstraint(
                'an audience given as an array must be a non-empty list (sequential integer keys from 0)',
            );
        }

        foreach ($audience as $value) {
            if (!is_string($value) || $value === '') {
                throw JwtConfigurationException::invalidClaimConstraint(
                    'an audience list must contain only non-empty strings',
                );
            }
        }
    }
}
