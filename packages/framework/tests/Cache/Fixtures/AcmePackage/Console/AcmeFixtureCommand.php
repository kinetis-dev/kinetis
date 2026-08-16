<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache\Fixtures\AcmePackage\Console;

use Kinetis\Console\Attributes\Command;

final readonly class AcmeFixtureCommand
{
    #[Command('acme:ping', description: 'Package-provided fixture command.')]
    public function run(): int
    {
        return 0;
    }
}
