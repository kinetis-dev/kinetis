<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache\Fixtures\Domain\Orders;

use Kinetis\Console\Attributes\Command;

final class UnconventionalPingCommand
{
    #[Command('fixture:unconventional', description: 'A command discovered outside any Console-named directory')]
    public function run(): int
    {
        return 0;
    }
}
