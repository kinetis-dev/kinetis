<?php

declare(strict_types=1);

namespace Kinetis\Views\Console;

use Kinetis\Console\Attributes\Command;
use Kinetis\Views\Views;

final readonly class ClearCommand
{
    /** @param resource $output */
    public function __construct(
        private Views $views,
        private mixed $output = STDOUT,
    ) {}

    #[Command('views:clear', description: 'Empties the selected view engine cache directory')]
    public function run(): int
    {
        $removed = $this->views->clearCache();
        fwrite($this->output, "Removed {$removed} cached view file(s).\n");

        return 0;
    }
}
