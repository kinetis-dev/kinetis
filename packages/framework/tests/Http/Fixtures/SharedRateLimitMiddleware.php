<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Middleware\RateLimitMiddleware;
use Psr\SimpleCache\CacheInterface;

/**
 * The thin subclass an application writes so #[Middleware(...)] — which
 * carries only a class-string — reaches a policy with a real ID and
 * limits. Registered both globally and on a route by
 * RateLimitMiddlewareTest, which is the redundant-registration shape the
 * per-request dedup exists for.
 */
final class SharedRateLimitMiddleware extends RateLimitMiddleware
{
    public function __construct(CacheInterface $cache)
    {
        parent::__construct($cache, 'fixture-shared', maxAttempts: 2, windowSeconds: 60);
    }
}
