<?php

declare(strict_types=1);

namespace Kinetis\Tests\Console\Fixtures;

use Kinetis\Console\Attributes\Command;

final class DuplicateCommandNameB
{
    #[Command('app:duplicate')]
    public function second(): void
    {
    }
}
