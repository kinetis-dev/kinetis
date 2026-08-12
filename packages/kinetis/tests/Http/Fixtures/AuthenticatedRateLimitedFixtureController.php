<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;

#[Middleware(CurrentUserMiddleware::class)]
#[Middleware(StrictAuthenticatedRateLimitMiddleware::class)]
final readonly class AuthenticatedRateLimitedFixtureController
{
    #[Get('/limited-by-user')]
    public function index(): array
    {
        return ['ok' => true];
    }
}
