<?php

declare(strict_types=1);

namespace Kinetis\Views\Console;

use Kinetis\Console\Attributes\Command;
use Kinetis\Console\Output;
use Kinetis\Views\Views;

final readonly class ClearCommand
{
    public function __construct(
        private Views $views,
        private Output $output,
    ) {}

    #[Command('views:clear', description: 'Empties the selected view engine cache directory')]
    public function run(): int
    {
        $removed = $this->views->clearCache();
        $this->output->line("Removed {$removed} cached view file(s).");

        return 0;
    }
}
