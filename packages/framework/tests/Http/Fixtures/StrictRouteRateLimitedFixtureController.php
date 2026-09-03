<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;

final readonly class StrictRouteRateLimitedFixtureController
{
    #[Get('/strict-limited')]
    #[Middleware(StrictRouteRateLimitMiddleware::class)]
    public function index(): array
    {
        return ['ok' => true];
    }
}
