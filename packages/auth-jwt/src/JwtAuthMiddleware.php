<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt;

use Kinetis\AuthJwt\Exception\JwtConfigurationException;
use Kinetis\Container\RequestScope;
use Kinetis\Http\Auth\BearerCredentialParser;
use Kinetis\Http\CurrentUserInterface;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Route middleware only — never register this globally. A public route
 * (a health check, /openapi.json, a login endpoint) must stay reachable
 * without a token, and route middleware only wraps
 * Dispatcher::dispatch(), which only ever runs after a successful route
 * match — the same reasoning Kinetis\Auth\BearerAuthMiddleware
 * documents.
 *
 * Resolved fresh per request from the route's own RequestScope, so
 * constructor-injecting RequestScope directly has no singleton-safety
 * concern.
 *
 * The `Authorization` header itself is parsed by
 * Kinetis\Http\Auth\BearerCredentialParser, which documents the exact
 * accepted wire grammar once and shares it with
 * Kinetis\Auth\BearerAuthMiddleware.
 *
 * $keys is a JwtVerificationKeys — a shared HMAC secret, one RSA public
 * key, or a JWK Set selecting by `kid`. It owns the algorithm and the
 * key material and validated both when it was built, so a misconfigured
 * middleware throws where the configuration is written rather than as a
 * client-facing 401 masking a server-side mistake.
 *
 * No storage lookup for authentication itself: verifying a JWT's
 * signature is the entire authentication decision, and introducing a
 * UserProviderInterface equivalent would mean a database round trip on
 * every request. $revocationStore is the one optional exception: one or
 * two cache lookups, opt-in, for the one thing a bare signature check
 * structurally cannot do — reject a token before it would otherwise
 * expire, individually (isRevoked()) or as a "log out everywhere" for
 * its subject (isRevokedForUser()). Configuring it also tightens what
 * counts as a valid token: `iat` and `jti` are otherwise optional per
 * the JWT standard, but with a revocation store in place both must be
 * present and well-formed (`iat` a plain integer, `jti` a non-empty
 * string) before either lookup runs.
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
 * untrusted context looks like. Both are checked before revocation,
 * scope registration, or the handler. Configure the matching values on
 * JwtIssuer's own $issuer/$audience.
 *
 * A token's JOSE header is read and validated by JoseHeader before any
 * of it reaches verification. A decode failure (expired, bad signature,
 * malformed, unknown kid, wrong key), a token whose `sub` is not a
 * non-empty string, a token failing an issuer/audience check, and a
 * revoked token are all treated identically — 401 with
 * WWW-Authenticate: Bearer, matching
 * Kinetis\Auth\BearerAuthMiddleware's failure shape exactly.
 *
 * Deliberately not final — the same exception to this codebase's
 * near-universal final convention that
 * Kinetis\Http\Middleware\RateLimitMiddleware documents, and for the
 * same reason: #[Middleware(...)] carries only a class-string, with
 * nowhere to pass $keys. Registering JwtAuthMiddleware::class directly
 * on AppScope with a factory that also supplies RequestScope would be
 * wrong regardless of the final question — a factory calling
 * $c->get(RequestScope::class) where $c is AppScope throws
 * DisconnectedRequestScopeException rather than reaching the real
 * per-request one. The correct pattern is a thin subclass supplying
 * $keys, with a constructor that takes only class-typed parameters
 * (RequestScope, optionally Config) — fully autowirable through the
 * request's own RequestScope:
 *
 *     final class AppJwtAuthMiddleware extends JwtAuthMiddleware
 *     {
 *         public function __construct(RequestScope $scope, Config $config)
 *         {
 *             parent::__construct(
 *                 JwtVerificationKeys::hmacSecret($config->required('JWT_SECRET')),
 *                 $scope,
 *             );
 *         }
 *     }
 */
class JwtAuthMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string>|null $acceptedAudiences
     */
    public function __construct(
        private JwtVerificationKeys $keys,
        private RequestScope $scope,
        private ?RevocationStore $revocationStore = null,
        private ?string $expectedIssuer = null,
        private ?array $acceptedAudiences = null,
    ) {
        self::assertValidIssuerAndAudiences($expectedIssuer, $acceptedAudiences);
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = BearerCredentialParser::parse($request);

        if ($token === null) {
            return $this->unauthorized();
        }

        $header = JoseHeader::parse($token, kidRequired: $this->keys->requiresKid());

        if ($header === null) {
            return $this->unauthorized();
        }

        $claims = $this->keys->decode($token, $header->kid);

        if ($claims === null) {
            return $this->unauthorized();
        }

        // A subject is one canonical non-empty string here, the form
        // JwtIssuer writes and both stores key their per-subject
        // revocation by. A `sub` of any other shape — absent, a JSON
        // number, an empty string — is a token this package cannot
        // revoke consistently, so it never authenticates one.
        $sub = $claims->sub ?? null;

        if (!is_string($sub) || $sub === '') {
            return $this->unauthorized();
        }

        if ($this->expectedIssuer !== null) {
            $iss = $claims->iss ?? null;

            if (!is_string($iss) || $iss !== $this->expectedIssuer) {
                return $this->unauthorized();
            }
        }

        if ($this->acceptedAudiences !== null && !$this->audienceMatches($claims->aud ?? null)) {
            return $this->unauthorized();
        }

        if ($this->revocationStore !== null) {
            $iat = $claims->iat ?? null;
            $jti = $claims->jti ?? null;

            // Both claims are validated together, before either lookup
            // runs: a numeric-string, fractional, or missing iat, or a
            // missing/empty jti, is rejected outright rather than
            // silently skipping the one check that claim would drive.
            if (!is_int($iat) || !is_string($jti) || $jti === '') {
                return $this->unauthorized();
            }

            if ($this->revocationStore->isRevokedForUser($sub, $iat)) {
                return $this->unauthorized();
            }

            if ($this->revocationStore->isRevoked($jti)) {
                return $this->unauthorized();
            }
        }

        // The same JwtUser instance under both ids — a controller that
        // only needs the identity contract injects CurrentUserInterface,
        // one that needs a specific claim injects the concrete JwtUser.
        // RequestScope resolves exact ids only, so registering under one
        // alone would leave the other unresolvable — worse, silently
        // autowiring a *new*, disconnected JwtUser.
        $user = new JwtUser($claims);
        $this->scope->instance(CurrentUserInterface::class, $user);
        $this->scope->instance(JwtUser::class, $user);

        return $handler->handle($request);
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

    private function unauthorized(): ResponseInterface
    {
        return new Response(
            status: 401,
            headers: [
                'Content-Type' => 'application/json',
                'WWW-Authenticate' => 'Bearer',
            ],
            body: json_encode(['error' => 'Unauthenticated.'], JSON_THROW_ON_ERROR),
        );
    }
}
