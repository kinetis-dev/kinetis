<?php

declare(strict_types=1);

namespace Kinetis\Auth\Tests\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\CurrentUserInterface;

#[Middleware('@api')]
final readonly class GroupedFixtureController
{
    public function __construct(
        private CurrentUserInterface $user,
    ) {}

    #[Get('/grouped')]
    public function show(): array
    {
        return ['userId' => $this->user->id()];
    }
}
