<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\CurrentUserInterface;

#[Middleware(CurrentUserMiddleware::class)]
final readonly class CurrentUserController
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
