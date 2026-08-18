<?php

declare(strict_types=1);

namespace Kinetis\Tests\Reflection\Fixtures;

use Kinetis\Console\Attributes\Command;

abstract class AbstractCommandBase
{
    #[Command('inherited:cmd')]
    public function run(): int
    {
        return 0;
    }
}
