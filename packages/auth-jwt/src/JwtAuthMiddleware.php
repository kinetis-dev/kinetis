<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt;

use DomainException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Kinetis\Container\RequestScope;
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
 * its subject (isRevokedForUser()).
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
 * $key also accepts an array<string, Key> — one or more keys, each
 * under its own kid — for rolling a signing key over without
 * invalidating every token issued under the previous one: a token's own
 * (unverified) kid header selects which entry to verify against. A
 * plain string keeps working exactly as before; $algorithm only applies
 * to that single-key form, since each Key in the array carries its own
 * algorithm already.
 *
 * A decode failure (expired, bad signature, malformed, wrong key), a
 * structurally valid but subject-less token, and a revoked token are all
 * treated identically — 401 with WWW-Authenticate: Bearer, matching
 * Kinetis\Auth\BearerAuthMiddleware's failure shape exactly. An empty or
 * malformed key is not caught here — that's a misconfiguration on this
 * app's own side, not a client-supplied bad token, and should surface
 * loudly rather than be silently swallowed into a 401. In practice this
 * means Config::required('JWT_SECRET') at construction time, not
 * Config::string('JWT_SECRET', '') with an empty-string default — the
 * empty string doesn't fail here at all; it reaches JWT::decode(), which
 * throws Firebase\JWT\InvalidArgumentException ("Key material must not
 * be empty") for a too-short/empty key. That's neither
 * UnexpectedValueException nor DomainException, so process()'s own catch
 * clause doesn't swallow it into a 401 either — it propagates uncaught,
 * surfacing as a generic 500 via ExceptionHandlerMiddleware, several
 * layers away from the actual missing-config root cause.
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
     * @param string|array<string, Key> $key
     */
    public function __construct(
        private string|array $key,
        private RequestScope $scope,
        private string $algorithm = 'HS256',
        private ?RevocationStore $revocationStore = null,
    ) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $header = $request->getHeaderLine('Authorization');

        if (!str_starts_with($header, 'Bearer ')) {
            return $this->unauthorized();
        }

        $token = substr($header, 7);

        if ($token === '') {
            return $this->unauthorized();
        }

        $keyOrKeyArray = is_string($this->key) ? new Key($this->key, $this->algorithm) : $this->key;

        try {
            $claims = JWT::decode($token, $keyOrKeyArray);
        } catch (UnexpectedValueException|DomainException) {
            return $this->unauthorized();
        }

        $sub = $claims->sub ?? null;

        if (!is_string($sub) && !is_int($sub)) {
            return $this->unauthorized();
        }

        if ($this->revocationStore !== null) {
            $iat = $claims->iat ?? null;

            if (is_numeric($iat) && $this->revocationStore->isRevokedForUser($sub, (int) $iat)) {
                return $this->unauthorized();
            }

            $jti = $claims->jti ?? null;

            if (is_string($jti) && $this->revocationStore->isRevoked($jti)) {
                return $this->unauthorized();
            }
        }

        $this->scope->instance(CurrentUserInterface::class, new JwtUser($claims));

        return $handler->handle($request);
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
