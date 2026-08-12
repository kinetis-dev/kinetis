<?php

declare(strict_types=1);

namespace Kinetis\Tests\Container\Fixtures;

final class Counter
{
    public int $count = 0;

    public function increment(): void
    {
        $this->count++;
    }
}
