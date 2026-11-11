<?php

declare(strict_types=1);

namespace Kinetis\Tests\Testing\Fixtures\CommandProject;

use Kinetis\Console\Attributes\Command;
use Kinetis\Console\Color;
use Kinetis\Console\CommandArguments;
use Kinetis\Console\Output;

final readonly class GreetCommand
{
    public function __construct(
        private Output $output,
    ) {}

    #[Command('fixture:greet')]
    public function run(CommandArguments $arguments): int
    {
        $name = $arguments->get(0);

        if ($name === null) {
            $this->output->error('Usage: fixture:greet <name>', Color::Red);

            return 2;
        }

        $this->output->line("Hello, {$name}", Color::Green);
        $this->output->error('greeted one person');

        return 0;
    }
}
