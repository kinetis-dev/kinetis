<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt;

use DomainException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Kinetis\AuthJwt\Exception\JwtAuthMiddlewareException;
use Kinetis\Container\RequestScope;
use Kinetis\Http\Auth\BearerCredentialParser;
use Kinetis\Http\CurrentUserInterface;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use UnexpectedValueException;

/**
 * Route middleware only — never register this globally. A public route (a
 * health check, /openapi.json, a login endpoint) must stay reachable
 * without a token, and route middleware only wraps
 * Dispatcher::dispatch(), which only ever runs after a successful route
 * match — the same reasoning Kinetis\Auth\BearerAuthMiddleware documents.
 *
 * Resolved fresh per request from the route's own RequestScope, so —
 * like that middleware, and unlike Kinetis\Http\Middleware\RateLimitMiddleware
 * — constructor-injecting RequestScope directly has no singleton-safety
 * concern.
 *
 * The `Authorization` header itself is parsed by
 * Kinetis\Http\Auth\BearerCredentialParser — the exact accepted wire
 * grammar is documented there once and shared with
 * Kinetis\Auth\BearerAuthMiddleware, rather than duplicated and risking
 * drift between the two.
 *
 * No storage lookup for authentication itself: unlike
 * Kinetis\Auth\BearerAuthMiddleware, verifying a JWT's signature is the
 * entire authentication decision — there is no UserProviderInterface
 * equivalent here, deliberately, since introducing one would mean a
 * database round trip on every request, defeating the actual reason to
 * choose a JWT over Kinetis\Auth\BearerAuthMiddleware in the first place.
 * $revocationStore is the one optional exception: one or two cache
 * lookups, opt-in, for the one thing a bare signature check structurally
 * cannot do — reject a token before it would otherwise expire, either
 * individually (isRevoked()) or as part of a "log out everywhere" for
 * its subject (isRevokedForUser()). Configuring it also tightens what
 * counts as a valid token: `iat` and `jti` are otherwise optional per
 * the JWT standard, but with a revocation store in place both must be
 * present and well-formed (`iat` a plain integer, `jti` a non-empty
 * string) before either revocation lookup runs — a token missing or
 * malformed on just one of them is rejected outright, not silently
 * exempted from the check it happens to be missing the claim for.
 *
 * $expectedIssuer/$acceptedAudiences are a second, independent opt-in
 * boundary, closing a different gap than $revocationStore: a bare
 * signature check alone can't tell "signed with a key I trust" apart
 * from "issued for a context I trust" — two services sharing one HS256
 * secret will otherwise each accept a token the other one issued, with
 * nothing to stop it. When $expectedIssuer is set, a token's `iss`
 * claim must be a non-empty string matching it exactly. When
 * $acceptedAudiences is set, a token's `aud` claim — either a single
 * string or the JWT standard's list-of-strings form — must contain at
 * least one exact match against it. Either constraint rejects a
 * missing or malformed claim the same way it rejects a mismatched one;
 * unlike revocation, there's no "claim absent" exemption at all here,
 * since an absent `iss`/`aud` is exactly what a token from an untrusted
 * context looks like. Checked before revocation, scope registration, or
 * the handler runs. Configure the matching values on JwtIssuer's own
 * $issuer/$audience — not through $claims, which a caller could
 * override the same way $claims can't override sub/iat/jti/exp.
 *
 * $key is the shared secret for a symmetric algorithm (HS256/HS384/
 * HS512), or the *public* half of a key pair (as a PEM string) for an
 * asymmetric one (RS256/RS384/RS512) — JwtIssuer takes the matching
 * *private* half. Passing the same key to both sides only works for the
 * symmetric case; for RS256 that would mean signing and verifying with
 * the public key, which either fails outright or (worse, if ever mixed
 * up the other way) means the private key needed to stay secret is
 * sitting in code that only ever needs to verify tokens.
 *
 * $key also accepts more than one key at once, each under its own kid,
 * for rolling a signing key over without invalidating every token
 * issued under the previous one: a token's own (unverified) kid header
 * selects which entry to verify against. ParsedJwkSet is the form to
 * configure that from a published JWK Set, and the only one that can
 * hold every kid JwkSet publishes — see that class for why a PHP array
 * key cannot. An array<string, Key> is the other accepted form, for a
 * deployment holding PEM files rather than a JWKS.
 *
 * $algorithm has no effect at all on either multi-key form: each Key
 * already carries its own algorithm, so the top-level $algorithm is
 * never read once $key is anything but a string, and construction never
 * validates it in that case either — validating an argument with no
 * effect on behavior would only risk rejecting an otherwise-valid
 * construction over an unrelated, unused default.
 *
 * $algorithm and $key are validated at construction, via
 * JwtKeyValidator — never on the first request. For the single-key
 * (string) form: $algorithm must be one of the six this package
 * supports, and $key must fit it (an HMAC secret at least as long as
 * the algorithm's digest, or a parseable RSA public key of at least
 * 2048 bits). For the key-map (array) form: the map must be non-empty,
 * every value a Firebase\JWT\Key held to that identical rule, and every
 * kid accepted by JwtKeyValidator::isUsableKid(). A ParsedJwkSet passed
 * all of that before it could exist, so construction re-checks nothing
 * there. A misconfigured middleware
 * throws immediately, naming what's wrong (never the key material
 * itself) — not on the first request, and never as a client-facing 401
 * masking a server-side mistake, or an unrelated exception escaping
 * from deep inside JWT::decode().
 *
 * A token's JOSE header is read and validated by JoseHeader before any
 * of it reaches JWT::decode() — see that class for what a header must
 * be. A token failing there becomes the same 401 as any other unusable
 * token.
 *
 * A decode failure (expired, bad signature, malformed, wrong key), a
 * structurally valid but subject-less token, a token failing an
 * issuer/audience check, and a revoked token are all treated identically
 * — 401 with WWW-Authenticate: Bearer, matching
 * Kinetis\Auth\BearerAuthMiddleware's failure shape exactly.
 *
 * Deliberately not final — the same exception to this codebase's near-
 * universal final convention that Kinetis\Http\Middleware\RateLimitMiddleware
 * documents, and for the same reason: #[Middleware(...)] carries only a
 * class-string, with nowhere to pass $key. Registering
 * JwtAuthMiddleware::class directly on AppScope with a factory that also
 * supplies RequestScope would be wrong regardless of the final question —
 * a factory calling $c->get(RequestScope::class) where $c is AppScope
 * throws DisconnectedRequestScopeException rather than reaching the real
 * per-request one. The correct pattern is a thin subclass supplying $key,
 * with a constructor that takes only class-typed parameters (RequestScope,
 * optionally Config) — fully autowirable through the request's own
 * RequestScope, exactly like
 * Kinetis\Auth\BearerAuthMiddleware already is with zero binding at all:
 *
 *     final class AppJwtAuthMiddleware extends JwtAuthMiddleware
 *     {
 *         public function __construct(RequestScope $scope, Config $config)
 *         {
 *             parent::__construct($config->required('JWT_SECRET'), $scope);
 *         }
 *     }
 */
class JwtAuthMiddleware implements MiddlewareInterface
{
    /**
     * @param string|array<string, Key>|ParsedJwkSet $key
     * @param list<string>|null $acceptedAudiences
     */
    public function __construct(
        private string|array|ParsedJwkSet $key,
        private RequestScope $scope,
        private string $algorithm = 'HS256',
        private ?RevocationStore $revocationStore = null,
        private ?string $expectedIssuer = null,
        private ?array $acceptedAudiences = null,
    ) {
        self::assertValidKey($key, $algorithm);
        self::assertValidIssuerAndAudiences($expectedIssuer, $acceptedAudiences);
    }

    /**
     * A given array $key must be a non-empty map of non-empty string kid
     * to a real Key instance — checked below, never declared here as
     * array<string, Key>, since this method's own body is what
     * establishes that guarantee for a caller; declaring it already-true
     * on entry would make the validation that enforces it look like
     * unreachable code. mixed is a real, if unhelpful, answer to
     * PHPStan's own "specify the array's value type" requirement.
     *
     * A ParsedJwkSet needs nothing checked here — see this class's own
     * docblock.
     *
     * @param string|array<mixed>|ParsedJwkSet $key
     */
    private static function assertValidKey(string|array|ParsedJwkSet $key, string $algorithm): void
    {
        if ($key instanceof ParsedJwkSet) {
            return;
        }

        if (is_string($key)) {
            JwtKeyValidator::assertSupportedAlgorithm(
                $algorithm,
                static fn () => JwtAuthMiddlewareException::unsupportedAlgorithm($algorithm),
            );

            JwtKeyValidator::assertKeyMaterial(
                $algorithm,
                $key,
                'public',
                static fn () => JwtKeyValidator::isHmacAlgorithm($algorithm)
                    ? JwtAuthMiddlewareException::hmacSecretTooShort($algorithm)
                    : JwtAuthMiddlewareException::invalidRsaPublicKey(),
            );

            return;
        }

        if ($key === []) {
            throw JwtAuthMiddlewareException::emptyKeyMap();
        }

        foreach ($key as $kid => $entry) {
            if (!JwtKeyValidator::isUsableKidValue($kid)) {
                throw JwtAuthMiddlewareException::invalidKeyMapKid();
            }

            if (!$entry instanceof Key) {
                throw JwtAuthMiddlewareException::invalidKeyMapValue($kid);
            }

            $entryAlgorithm = $entry->getAlgorithm();

            JwtKeyValidator::assertSupportedAlgorithm(
                $entryAlgorithm,
                static fn () => JwtAuthMiddlewareException::unsupportedKeyMapAlgorithm($kid),
            );

            JwtKeyValidator::assertKeyMaterial(
                $entryAlgorithm,
                $entry->getKeyMaterial(),
                'public',
                static fn () => JwtAuthMiddlewareException::invalidKeyMapKeyMaterial($kid),
            );
        }
    }

    /**
     * @param ?list<string> $acceptedAudiences
     */
    private static function assertValidIssuerAndAudiences(?string $expectedIssuer, ?array $acceptedAudiences): void
    {
        if ($expectedIssuer === '') {
            throw JwtAuthMiddlewareException::emptyExpectedIssuer();
        }

        if ($acceptedAudiences === null) {
            return;
        }

        if ($acceptedAudiences === []) {
            throw JwtAuthMiddlewareException::emptyAcceptedAudiences();
        }

        if (!array_is_list($acceptedAudiences)) {
            throw JwtAuthMiddlewareException::acceptedAudiencesNotAList();
        }

        foreach ($acceptedAudiences as $audience) {
            if (!is_string($audience) || $audience === '') {
                throw JwtAuthMiddlewareException::invalidAcceptedAudience();
            }
        }
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = BearerCredentialParser::parse($request);

        if ($token === null) {
            return $this->unauthorized();
        }

        $header = JoseHeader::parse($token, kidRequired: !is_string($this->key));

        if ($header === null) {
            return $this->unauthorized();
        }

        // Resolving the kid here, rather than leaving it to
        // JWT::decode()'s own lookup, keeps an unknown kid a rejection
        // this class made: ParsedJwkSet::has() compares the exact
        // string the document published, with no PHP array key between
        // the token's kid and the key it selects.
        if ($this->key instanceof ParsedJwkSet && ($header->kid === null || !$this->key->has($header->kid))) {
            return $this->unauthorized();
        }

        $keyOrKeySet = is_string($this->key) ? new Key($this->key, $this->algorithm) : $this->key;

        try {
            $claims = JWT::decode($token, $keyOrKeySet);
        } catch (UnexpectedValueException|DomainException) {
            return $this->unauthorized();
        }

        $sub = $claims->sub ?? null;

        if (!is_string($sub) && !is_int($sub)) {
            return $this->unauthorized();
        }

        if ($this->expectedIssuer !== null) {
            $iss = $claims->iss ?? null;

            if (!is_string($iss) || $iss === '' || $iss !== $this->expectedIssuer) {
                return $this->unauthorized();
            }
        }

        if ($this->acceptedAudiences !== null) {
            $aud = $claims->aud ?? null;

            if (!$this->audienceMatches($aud, $this->acceptedAudiences)) {
                return $this->unauthorized();
            }
        }

        if ($this->revocationStore !== null) {
            $iat = $claims->iat ?? null;
            $jti = $claims->jti ?? null;

            // Both claims are validated together, before either lookup
            // runs — see this class's own docblock. A numeric-string,
            // fractional, or missing iat, or a missing/empty jti, is
            // rejected outright rather than silently skipping just the
            // one check that claim would have driven.
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
        // one that needs a specific claim (revocation's own jti, most
        // commonly) injects the concrete JwtUser directly, per this
        // class's own docs. RequestScope resolves exact ids only, so
        // registering under one alone would leave the other
        // unresolvable — worse, silently autowiring a *new*, disconnected
        // JwtUser via its unresolvable stdClass $claims constructor
        // parameter, not simply failing to find one.
        $user = new JwtUser($claims);
        $this->scope->instance(CurrentUserInterface::class, $user);
        $this->scope->instance(JwtUser::class, $user);

        return $handler->handle($request);
    }

    /**
     * A token's `aud` claim, per the JWT standard, may be either a single
     * string or an array of strings — either shape matches as long as at
     * least one value in it is present in $accepted. Anything else
     * (missing, not a string, an empty string, an empty array, or an
     * array containing a non-string/empty-string element) doesn't match
     * — a malformed claim is rejected the same as one that just doesn't
     * contain any accepted value, never partially validated.
     *
     * @param list<string> $accepted
     */
    private function audienceMatches(mixed $aud, array $accepted): bool
    {
        if (is_string($aud)) {
            return $aud !== '' && in_array($aud, $accepted, true);
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
