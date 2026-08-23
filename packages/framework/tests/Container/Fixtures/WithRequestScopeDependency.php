<?php

declare(strict_types=1);

namespace Kinetis\Tests\Container\Fixtures;

use Kinetis\Container\RequestScope;

/**
 * The JwtAuthMiddleware/AuthenticatedRateLimitMiddleware/EventDispatcher
 * shape: a class meant to be resolved through a request's own scope, never
 * autowired directly by AppScope. Reproduces the indirect path
 * DisconnectedRequestScopeException exists to catch — resolving this class
 * *through AppScope* bottoms out in AppScope trying to resolve
 * RequestScope::class for its constructor.
 */
final class WithRequestScopeDependency
{
    public function __construct(
        public readonly RequestScope $scope,
    ) {}
}
