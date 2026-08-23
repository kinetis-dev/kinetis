<?php

declare(strict_types=1);

namespace Kinetis\Auth\Tests\Fixtures;

use Kinetis\Auth\BearerAuthMiddleware;
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\CurrentUserInterface;

#[Middleware(BearerAuthMiddleware::class)]
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
