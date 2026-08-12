<?php

declare(strict_types=1);

namespace Kinetis\Auth\Tests\Fixtures;

use Kinetis\Http\CurrentUserInterface;

final readonly class FixtureUser implements CurrentUserInterface
{
    public function __construct(
        private string|int $userId,
    ) {}

    public function id(): string|int
    {
        return $this->userId;
    }
}
