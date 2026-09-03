<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Middleware\RateLimitMiddleware;
use Psr\SimpleCache\CacheInterface;

final class StrictRouteRateLimitMiddleware extends RateLimitMiddleware
{
    public function __construct(CacheInterface $cache)
    {
        parent::__construct($cache, maxAttempts: 1, windowSeconds: 60);
    }
}
