<?php

declare(strict_types=1);

namespace Kinetis\Authorization\Tests\Fixtures;

final readonly class FixturePost
{
    public function __construct(
        public int $authorId,
        public bool $locked = false,
    ) {}
}
