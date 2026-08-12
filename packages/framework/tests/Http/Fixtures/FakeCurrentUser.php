<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\CurrentUserInterface;

final readonly class FakeCurrentUser implements CurrentUserInterface
{
    public function __construct(
        private string|int $userId,
    ) {}

    public function id(): string|int
    {
        return $this->userId;
    }
}
