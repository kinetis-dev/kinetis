<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Middleware\RateLimitMiddleware;

final readonly class RateLimitedFixtureController
{
    #[Get('/limited')]
    #[Middleware(RateLimitMiddleware::class)]
    public function index(): array
    {
        return ['ok' => true];
    }
}
