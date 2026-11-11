<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Console;

use Kinetis\Config\Config;
use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\Console\Output;
use Kinetis\Migrations\MigrationRunner;
use Kinetis\Runtime\ProjectRoot;

final readonly class StatusCommand
{
    public function __construct(
        private Output $output,
        private ProjectRoot $projectRoot,
        private Config $config,
    ) {}

    #[Command('migrate:status', description: 'List applied and pending migrations on every connection partition. --connection=<name> lists one.', bootstrap: false)]
    public function run(CommandArguments $arguments): int
    {
        return $this->runIn(MigrationContext::forProject($this->projectRoot, $this->config), $arguments);
    }

    /** @internal */
    public function runIn(MigrationContext $context, CommandArguments $arguments): int
    {
        $partitions = $context->partitions($arguments);

        foreach ($partitions as $connection => $path) {
            if (\count($partitions) > 1) {
                $this->output->line("Connection: {$connection}");
            }

            $status = $context->run($connection, $path, static fn (MigrationRunner $runner): array => $runner->status());

            if ($status === []) {
                $this->output->line('No migrations found.');

                continue;
            }

            foreach ($status as $entry) {
                $marker = $entry['applied'] ? '[applied]' : '[pending]';
                $this->output->line("{$marker} {$entry['name']}");
            }
        }

        return 0;
    }
}
