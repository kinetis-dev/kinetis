<?php

declare(strict_types=1);

namespace Kinetis\Tests\Container\Fixtures;

final class ServiceA
{
    public function __construct(
        public readonly Counter $counter,
    ) {
        $counter->increment();
    }
}
