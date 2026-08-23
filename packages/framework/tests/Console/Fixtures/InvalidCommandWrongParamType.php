<?php

declare(strict_types=1);

namespace Kinetis\Tests\Console\Fixtures;

use Kinetis\Console\Attributes\Command;

final class InvalidCommandWrongParamType
{
    #[Command('app:invalid')]
    public function invalid(string $notCommandArguments): void
    {
    }
}
