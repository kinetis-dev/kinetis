<?php

declare(strict_types=1);

namespace Kinetis\Views\Console;

use Kinetis\Console\Attributes\Command;
use Kinetis\Console\Output;
use Kinetis\Views\Views;

final readonly class WarmCommand
{
    public function __construct(
        private Views $views,
        private Output $output,
    ) {}

    #[Command('views:warm', description: 'Rebuilds the selected view engine cache from the configured view directory')]
    public function run(): int
    {
        $warmed = $this->views->warmCache();
        $this->output->line("Warmed {$warmed} view template(s).");

        return 0;
    }
}
