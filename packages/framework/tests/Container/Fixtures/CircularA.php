<?php

declare(strict_types=1);

namespace Kinetis\Tests\Container\Fixtures;

final class CircularA
{
    public function __construct(
        public readonly CircularB $b,
    ) {}
}
