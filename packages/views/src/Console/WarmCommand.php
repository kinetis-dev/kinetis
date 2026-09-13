<?php

declare(strict_types=1);

namespace Kinetis\Views\Console;

use Kinetis\Console\Attributes\Command;
use Kinetis\Views\Views;

final readonly class WarmCommand
{
    /** @param resource $output */
    public function __construct(
        private Views $views,
        private mixed $output = STDOUT,
    ) {}

    #[Command('views:warm', description: 'Rebuilds the selected view engine cache from the configured view directory')]
    public function run(): int
    {
        $warmed = $this->views->warmCache();
        fwrite($this->output, "Warmed {$warmed} view template(s).\n");

        return 0;
    }
}
