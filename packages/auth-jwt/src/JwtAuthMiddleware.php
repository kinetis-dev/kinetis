<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt;

use Kinetis\Container\RequestScope;
use Kinetis\Http\Auth\AuthorizationToken68Parser;
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
 * Transport only: the `Authorization` header, the 401, and publishing
 * the authenticated user on the request. Every cryptographic and claim
 * decision belongs to JwtAuthenticator, which is registered once on
 * AppScope and injected here — so this class is autowired straight from
 * the route's own RequestScope, with no subclass supplying
 * configuration.
 *
 * The header is parsed by Kinetis\Http\Auth\AuthorizationToken68Parser
 * with the `Bearer` scheme, which documents the exact accepted wire
 * grammar once and shares it with Kinetis\Auth\BearerAuthMiddleware.
 *
 * A header this package will not parse and a token JwtAuthenticator
 * will not accept are one answer: 401 with WWW-Authenticate: Bearer,
 * matching Kinetis\Auth\BearerAuthMiddleware's failure shape exactly.
 *
 * Deliberately not final — the same exception to this codebase's
 * near-universal final convention that
 * Kinetis\Http\Middleware\RateLimitMiddleware documents, and for the
 * same reason: an attribute only attaches to a class by declaring it
 * there, so joining a middleware group (#[AsMiddlewareGroup('mcp')] on
 * an otherwise empty subclass) requires one. That is the whole
 * supported extension: this class's own state is private and readonly,
 * and configuration is changed by registering a different
 * JwtAuthenticator, never by a subclass constructor.
 */
class JwtAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly JwtAuthenticator $authenticator,
        private readonly RequestScope $scope,
    ) {}

    #[\Override]
    public function process(
        #[\SensitiveParameter] ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $token = AuthorizationToken68Parser::parse($request, 'Bearer');

        if ($token === null) {
            return $this->unauthorized();
        }

        $user = $this->authenticator->authenticate($token);

        if ($user === null) {
            return $this->unauthorized();
        }

        // The same JwtUser instance under both ids — a controller that
        // only needs the identity contract injects CurrentUserInterface,
        // one that needs a specific claim injects the concrete JwtUser.
        // RequestScope resolves exact ids only, so registering under one
        // alone would leave the other unresolvable — worse, silently
        // autowiring a *new*, disconnected JwtUser.
        $this->scope->instance(CurrentUserInterface::class, $user);
        $this->scope->instance(JwtUser::class, $user);

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
