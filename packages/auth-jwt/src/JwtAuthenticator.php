<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt;

use Kinetis\AuthJwt\Exception\JwtConfigurationException;

/**
 * The whole JWT authentication decision, with no HTTP in it: hand it a
 * token's bytes, get back the JwtUser they name or null. Immutable,
 * request-neutral, and validated at construction, so one instance is
 * registered on AppScope and shared by every request a worker serves —
 * the token, the decoded claims and the returned JwtUser are all
 * method-local, and nothing about a request survives the call.
 *
 * $keys is a JwtVerificationKeys — a shared HMAC secret, one RSA public
 * key, or a JWK Set selecting by `kid`. It owns the algorithm and the
 * key material and validated both when it was built, so a misconfigured
 * authenticator throws where the configuration is written rather than
 * as a client-facing 401 masking a server-side mistake. A token's own
 * header never selects an algorithm.
 *
 * No storage lookup for authentication itself: verifying a JWT's
 * signature is the entire authentication decision, and introducing a
 * UserProviderInterface equivalent would mean a database round trip on
 * every request. $revocationStore is the one optional exception: a
 * single cache lookup, opt-in, for the one thing a bare signature check
 * structurally cannot do — reject an individual token before it would
 * otherwise expire (isRevoked()). Configuring it also tightens what
 * counts as a valid token: `jti` is otherwise optional per the JWT
 * standard, but with a revocation store in place it must be present and
 * a non-empty string before the lookup runs.
 *
 * $expectedIssuer/$acceptedAudiences are a second, independent opt-in
 * boundary, closing a different gap: a bare signature check cannot tell
 * "signed with a key I trust" apart from "issued for a context I trust",
 * so two services sharing one HS256 secret would otherwise each accept
 * the other's tokens. When $expectedIssuer is set, a token's `iss` claim
 * must be a non-empty string matching it exactly. When
 * $acceptedAudiences is set, a token's `aud` claim — a single string or
 * the JWT standard's list-of-strings form — must contain at least one
 * exact match. A missing or malformed claim is rejected the same way a
 * mismatched one is, since that is exactly what a token from an
 * untrusted context looks like. Both are checked before revocation.
 * Configure the matching values on JwtIssuer's own $issuer/$audience.
 */
final readonly class JwtAuthenticator
{
    /**
     * @param list<string>|null $acceptedAudiences
     */
    public function __construct(
        #[\SensitiveParameter] private JwtVerificationKeys $keys,
        private ?RevocationStore $revocationStore = null,
        private ?string $expectedIssuer = null,
        private ?array $acceptedAudiences = null,
    ) {
        self::assertValidIssuerAndAudiences($expectedIssuer, $acceptedAudiences);
    }

    /**
     * The verified user the token names, or null for every token this
     * configuration will not act on — a JOSE header it refuses, a
     * signature/expiry/key failure, a `sub` that is not one canonical
     * non-empty string, a rejected issuer or audience, a missing `jti`
     * where revocation is configured, and a token the denylist reports
     * revoked are one indistinguishable answer, so a caller's whole
     * vocabulary for an unusable credential is a generic 401.
     *
     * A revocation lookup that *fails* is not one of those: the
     * exception propagates, because a store that cannot answer has not
     * said the token is valid.
     */
    public function authenticate(#[\SensitiveParameter] string $token): ?JwtUser
    {
        $header = JoseHeader::parse($token, kidRequired: $this->keys->requiresKid());

        if ($header === null) {
            return null;
        }

        $claims = $this->keys->decode($token, $header->kid);

        if ($claims === null) {
            return null;
        }

        // A subject is one canonical non-empty string here, the form
        // JwtIssuer writes and RefreshTokenStore stores. A `sub` of any
        // other shape — absent, a JSON number, an empty string — names
        // no user this package can act on, so it never authenticates
        // one.
        $sub = $claims->sub ?? null;

        if (!is_string($sub) || $sub === '') {
            return null;
        }

        if ($this->expectedIssuer !== null) {
            $iss = $claims->iss ?? null;

            if (!is_string($iss) || $iss !== $this->expectedIssuer) {
                return null;
            }
        }

        if ($this->acceptedAudiences !== null && !$this->audienceMatches($claims->aud ?? null)) {
            return null;
        }

        if ($this->revocationStore !== null) {
            $jti = $claims->jti ?? null;

            // A missing or empty jti is rejected outright rather than
            // silently skipping the lookup it would have driven: a token
            // that cannot be named on the denylist can never be revoked.
            if (!is_string($jti) || $jti === '') {
                return null;
            }

            if ($this->revocationStore->isRevoked($jti)) {
                return null;
            }
        }

        return new JwtUser($claims);
    }

    /**
     * @param ?list<string> $acceptedAudiences
     */
    private static function assertValidIssuerAndAudiences(?string $expectedIssuer, ?array $acceptedAudiences): void
    {
        if ($expectedIssuer === '') {
            throw JwtConfigurationException::invalidClaimConstraint(
                'an expected issuer must be a non-empty string, or null to accept any issuer',
            );
        }

        if ($acceptedAudiences === null) {
            return;
        }

        if ($acceptedAudiences === [] || !array_is_list($acceptedAudiences)) {
            throw JwtConfigurationException::invalidClaimConstraint(
                'accepted audiences must be a non-empty list (sequential integer keys from 0), or null '
                . 'to accept any audience',
            );
        }

        foreach ($acceptedAudiences as $audience) {
            if (!is_string($audience) || $audience === '') {
                throw JwtConfigurationException::invalidClaimConstraint(
                    'accepted audiences must contain only non-empty strings',
                );
            }
        }
    }

    /**
     * A token's `aud` claim, per the JWT standard, may be either a
     * single string or an array of strings — either shape matches as
     * long as at least one value in it is accepted. Anything else
     * (missing, not a string, empty, or an array carrying a
     * non-string/empty-string element) does not match: a malformed claim
     * is rejected the same as one that simply names no accepted value.
     */
    private function audienceMatches(mixed $aud): bool
    {
        $accepted = $this->acceptedAudiences ?? [];

        if (is_string($aud)) {
            return in_array($aud, $accepted, true);
        }

        if (!is_array($aud) || $aud === []) {
            return false;
        }

        foreach ($aud as $value) {
            if (!is_string($value) || $value === '') {
                return false;
            }
        }

        return array_intersect($aud, $accepted) !== [];
    }
}
