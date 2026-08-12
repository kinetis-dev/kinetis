<?php

declare(strict_types=1);

namespace Kinetis\Tests\Container\Fixtures;

final class WithDefault
{
    public function __construct(
        public readonly int $limit = 10,
    ) {}
}
