<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Console;

use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;

final readonly class StatusCommand
{
    #[Command('migrate:status', description: 'List applied and pending migrations. --connection=<name> targets a named DB_* block.', bootstrap: false)]
    public function run(CommandArguments $arguments): int
    {
        $status = MigrationContext::detect()->runner($arguments)->status();

        if ($status === []) {
            fwrite(STDOUT, "No migrations found.\n");

            return 0;
        }

        foreach ($status as $entry) {
            $marker = $entry['applied'] ? '[applied]' : '[pending]';
            fwrite(STDOUT, "{$marker} {$entry['name']}\n");
        }

        return 0;
    }
}
