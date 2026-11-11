<?php

declare(strict_types=1);

namespace Kinetis\Tests\Testing\Fixtures\CommandProject;

use Kinetis\Console\Attributes\Command;
use Kinetis\Console\Output;
use RuntimeException;

final readonly class FailingCommand
{
    public function __construct(
        private Output $output,
    ) {}

    #[Command('fixture:fail')]
    public function run(): void
    {
        $this->output->line('starting');

        throw new RuntimeException('fixture failure');
    }
}
