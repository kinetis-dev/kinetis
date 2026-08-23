<?php

declare(strict_types=1);

namespace Kinetis\Tests\Console\Fixtures;

use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use RuntimeException;

final class MaintenanceController
{
    #[Command('app:no-args', description: 'Takes no arguments, implicit success')]
    public function noArgs(): void
    {
    }

    #[Command('app:with-args', description: 'Reports how many positional arguments it received')]
    public function withArgs(CommandArguments $arguments): int
    {
        return count($arguments->all());
    }

    #[Command('app:explicit-failure', description: 'Always exits with code 2')]
    public function explicitFailure(): int
    {
        return 2;
    }

    #[Command('app:throws', description: 'Always throws')]
    public function throws(): void
    {
        throw new RuntimeException('deliberately broken');
    }
}
