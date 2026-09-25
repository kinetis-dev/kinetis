<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Console;

use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\Migrations\MigrationRunner;

final readonly class StatusCommand
{
    #[Command('migrate:status', description: 'List applied and pending migrations on every connection partition. --connection=<name> lists one.', bootstrap: false)]
    public function run(CommandArguments $arguments): int
    {
        return $this->runIn(MigrationContext::detect(), $arguments);
    }

    /** @internal */
    public function runIn(MigrationContext $context, CommandArguments $arguments): int
    {
        $partitions = $context->partitions($arguments);

        foreach ($partitions as $connection => $path) {
            if (\count($partitions) > 1) {
                fwrite(STDOUT, "Connection: {$connection}\n");
            }

            $status = $context->run($connection, $path, static fn (MigrationRunner $runner): array => $runner->status());

            if ($status === []) {
                fwrite(STDOUT, "No migrations found.\n");

                continue;
            }

            foreach ($status as $entry) {
                $marker = $entry['applied'] ? '[applied]' : '[pending]';
                fwrite(STDOUT, "{$marker} {$entry['name']}\n");
            }
        }

        return 0;
    }
}
