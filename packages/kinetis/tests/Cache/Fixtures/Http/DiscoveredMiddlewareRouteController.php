<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache\Fixtures\Http;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;

#[Middleware(RouteLevelMiddlewareA::class)]
final class DiscoveredMiddlewareRouteController
{
    #[Get('/fixture-with-middleware')]
    #[Middleware(RouteLevelMiddlewareB::class)]
    public function show(): string
    {
        return 'ok';
    }
}
