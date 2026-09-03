<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

final readonly class IterableFieldRequest
{
    public function __construct(
        public iterable $items,
    ) {}
}
