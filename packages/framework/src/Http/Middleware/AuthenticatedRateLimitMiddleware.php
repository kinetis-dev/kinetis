<?php

declare(strict_types=1);

namespace Kinetis\Http\Middleware;

use Kinetis\Container\RequestScope;
use Kinetis\Http\CurrentUserInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Keys by the authenticated user when one has already been resolved onto
 * the current RequestScope (see {@see CurrentUserInterface}'s own
 * docblock, and the "Registering a value the controller reads later"
 * pattern any auth middleware uses), falling back to
 * RateLimitMiddleware's own IP-based identifier otherwise — a request
 * that hasn't been through an auth middleware yet, or an app with no auth
 * at all, behaves identically to the parent class.
 *
 * Route middleware only — never register this globally, and never bind
 * it directly on AppScope with a factory that also resolves RequestScope.
 * Both would hit the same hazard Kinetis\Auth\BearerAuthMiddleware and
 * Kinetis\AuthJwt\JwtAuthMiddleware's own docblocks describe: a factory
 * calling $c->get(RequestScope::class) where $c is AppScope throws
 * DisconnectedRequestScopeException rather than reaching the real
 * per-request one — and even if that hazard didn't exist, global
 * middleware runs before routing, which is chronologically before any
 * route middleware (including auth) has had a chance to register a
 * CurrentUserInterface at all, so user-based keying could never actually
 * apply there regardless.
 *
 * Ordering matters: register the auth middleware first (outermost) and
 * this one after it — #[Middleware(AuthMiddleware::class)]
 * #[Middleware(AuthenticatedRateLimitMiddleware::class)] — so
 * CurrentUserInterface is already registered on the scope by the time
 * this runs.
 *
 * Constructor-injecting RequestScope directly has no singleton-safety
 * concern here, unlike the parent class: this is always resolved fresh
 * per request through the route's own RequestScope, never as an
 * AppScope-resolved singleton.
 *
 * Deliberately not final, same reason as the parent class: two routes
 * needing two different limits at the same time still needs a thin
 * subclass overriding the constructor defaults.
 */
class AuthenticatedRateLimitMiddleware extends RateLimitMiddleware
{
    /**
     * @param list<string> $trustedProxies see the parent class — forwarded unchanged.
     */
    public function __construct(
        CacheInterface $cache,
        private readonly RequestScope $scope,
        int $maxAttempts = 60,
        int $windowSeconds = 60,
        array $trustedProxies = [],
    ) {
        parent::__construct($cache, $maxAttempts, $windowSeconds, $trustedProxies);
    }

    #[\Override]
    protected function identifierFor(ServerRequestInterface $request): string
    {
        if (!$this->scope->has(CurrentUserInterface::class)) {
            return parent::identifierFor($request);
        }

        /** @var CurrentUserInterface $user */
        $user = $this->scope->get(CurrentUserInterface::class);

        return 'user:' . $user->id();
    }
}
