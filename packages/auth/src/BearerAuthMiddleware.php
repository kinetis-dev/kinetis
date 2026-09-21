<?php

declare(strict_types=1);

namespace Kinetis\Auth;

use Kinetis\Container\RequestScope;
use Kinetis\Http\Auth\AuthorizationToken68Parser;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\OpenApi\SecurityDescriberInterface;
use Kinetis\OpenApi\SecurityDescription;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Route middleware only — never register this globally. A public route (a
 * health check, /openapi.json, a login endpoint) must stay reachable
 * without a token, and route middleware only wraps Dispatcher::dispatch(),
 * which only ever runs after a successful route match — the mirror image
 * of why Kinetis\Http\Middleware\CorsMiddleware must instead run globally,
 * before routing.
 *
 * Resolved fresh per request from the route's own RequestScope (see
 * Kinetis\Http\Attributes\Middleware), so — unlike
 * Kinetis\Http\Middleware\RateLimitMiddleware, which must stay safe as a
 * worker-lifetime singleton — this class has no such constraint.
 * Constructor-injecting RequestScope directly is exactly the pattern
 * AppScope::createRequestScope()'s self-injection exists for.
 *
 * On success, registers the resolved user on the current RequestScope as
 * CurrentUserInterface — a controller (or any other package) depends on
 * that interface, never on UserProviderInterface or this middleware's
 * concrete implementation of it.
 *
 * The `Authorization` header itself is parsed by
 * Kinetis\Http\Auth\AuthorizationToken68Parser with the `Bearer`
 * scheme — the exact accepted wire grammar (case-insensitive scheme,
 * one-or-more-SP separator, the token68/b64token credential alphabet)
 * is documented there once and shared with
 * Kinetis\AuthJwt\JwtAuthMiddleware, rather than duplicated and risking
 * drift between the two.
 *
 * Not final, for the same reason JwtAuthMiddleware and
 * RateLimitMiddleware are not: an attribute only attaches to a class by
 * declaring it there, so joining a middleware group
 * (#[AsMiddlewareGroup('mcp')] on an otherwise empty subclass) requires
 * one. Such a subclass inherits openApiSecurity() with it, so a route
 * reaching this middleware through a group is documented exactly as one
 * naming the class directly.
 *
 * openApiSecurity() describes the wire mechanism every deployment of
 * this class enforces: an opaque bearer token in the `Authorization`
 * header. Which tokens exist and who they resolve to belongs to the
 * UserProviderInterface and to no part of the published document.
 */
readonly class BearerAuthMiddleware implements MiddlewareInterface, SecurityDescriberInterface
{
    /**
     * The scheme name this middleware publishes. Stable and distinct
     * from kinetis/auth-jwt's, because the two definitions differ: a
     * document naming both would otherwise have to publish one of them
     * as the other.
     */
    public const string SCHEME = 'bearerToken';

    public function __construct(
        private UserProviderInterface $users,
        private RequestScope $scope,
    ) {}

    #[\Override]
    public static function openApiSecurity(): SecurityDescription
    {
        return SecurityDescription::scheme(self::SCHEME, [
            'type' => 'http',
            'scheme' => 'bearer',
            'description' => 'An opaque token issued by this application, sent as "Authorization: Bearer <token>".',
        ]);
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = AuthorizationToken68Parser::parse($request, 'Bearer');

        if ($token === null) {
            return $this->unauthorized();
        }

        $user = $this->users->findByToken($token);

        if ($user === null) {
            return $this->unauthorized();
        }

        $this->scope->instance(CurrentUserInterface::class, $user);

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
