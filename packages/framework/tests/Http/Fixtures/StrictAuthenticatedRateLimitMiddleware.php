<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Container\RequestScope;
use Kinetis\Http\Middleware\AuthenticatedRateLimitMiddleware;
use Psr\SimpleCache\CacheInterface;

final class StrictAuthenticatedRateLimitMiddleware extends AuthenticatedRateLimitMiddleware
{
    public function __construct(CacheInterface $cache, RequestScope $scope)
    {
        parent::__construct($cache, $scope, maxAttempts: 1, windowSeconds: 60);
    }
}
