<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Tests\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\CurrentUserInterface;

#[Middleware(FixtureJwtAuthMiddleware::class)]
final readonly class ProtectedFixtureController
{
    public function __construct(
        private CurrentUserInterface $user,
    ) {}

    #[Get('/me')]
    public function show(): array
    {
        return ['userId' => $this->user->id()];
    }
}
