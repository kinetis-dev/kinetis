<?php

declare(strict_types=1);

namespace Kinetis\Tests\Console\Fixtures;

use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;

final class InvalidCommandTooManyParams
{
    #[Command('app:invalid')]
    public function invalid(CommandArguments $arguments, string $extra): void
    {
    }
}
