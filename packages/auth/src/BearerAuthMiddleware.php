<?php

declare(strict_types=1);

namespace Kinetis\Auth;

use Kinetis\Container\RequestScope;
use Kinetis\Http\CurrentUserInterface;
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
 * Not final, for the same reason JwtAuthMiddleware and
 * RateLimitMiddleware are not: an attribute only attaches to a class by
 * declaring it there, so joining a middleware group
 * (#[AsMiddlewareGroup('mcp')] on a thin subclass) requires one — as
 * does fixing constructor defaults, the reason the other two are open.
 */
readonly class BearerAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private UserProviderInterface $users,
        private RequestScope $scope,
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
