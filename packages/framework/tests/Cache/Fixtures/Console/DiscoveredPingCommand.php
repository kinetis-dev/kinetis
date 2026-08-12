<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache\Fixtures\Console;

use Kinetis\Console\Attributes\Command;

final class DiscoveredPingCommand
{
    #[Command('fixture:ping', description: 'A command discovered via namespace scanning')]
    public function run(): int
    {
        return 0;
    }
}
