<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Fixtures;

use Kinetis\Http\CurrentUserInterface;

final readonly class FakeCurrentUser implements CurrentUserInterface
{
    public function __construct(
        private string|int $userId,
    ) {}

    #[\Override]
    public function id(): string|int
    {
        return $this->userId;
    }
}
