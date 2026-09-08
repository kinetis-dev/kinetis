<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

final readonly class UnionTypedFieldRequest
{
    public function __construct(
        public int|string $identifier,
    ) {}
}
