<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Console;

use Kinetis\Config\Config;
use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\Console\Output;
use Kinetis\Events\EventDispatcher;
use Kinetis\Migrations\Events\MigrationApplied;
use Kinetis\Migrations\MigrationRunner;
use Kinetis\Runtime\ProjectRoot;
use Throwable;

final readonly class MigrateCommand
{
    public function __construct(
        private EventDispatcher $events,
        private Output $output,
        private ProjectRoot $projectRoot,
        private Config $config,
    ) {}

    #[Command('migrate', description: 'Apply pending migrations on every connection partition, default first. --connection=<name> migrates one.', bootstrap: false)]
    public function run(CommandArguments $arguments): int
    {
        return $this->runIn(MigrationContext::forProject($this->projectRoot, $this->config), $arguments);
    }

    /**
     * Partitions run one after another, each on its own link, and the
     * first failure stops the run: partitions before it stay migrated.
     *
     * @internal
     */
    public function runIn(MigrationContext $context, CommandArguments $arguments): int
    {
        $partitions = $context->partitions($arguments);
        $several = \count($partitions) > 1;

        if ($several) {
            // Every connection's configuration and ledger is checked
            // before any partition applies a migration, so a database
            // that cannot be migrated stops the run before it starts.
            // migrate() checks again under its own lock. A failure names
            // its connection the way the migrate pass would, since the
            // exception itself may not, on stderr beside the failure.
            foreach ($partitions as $connection => $path) {
                try {
                    $context->run($connection, $path, static fn (MigrationRunner $runner): array => $runner->status());
                } catch (Throwable $e) {
                    $this->output->error("Connection: {$connection}");

                    throw $e;
                }
            }
        }

        foreach ($partitions as $connection => $path) {
            if ($several) {
                $this->output->line("Connection: {$connection}");
            }

            $applied = $context->run($connection, $path, static fn (MigrationRunner $runner): array => $runner->migrate());

            if ($applied === []) {
                $this->output->line('Nothing to migrate.');

                continue;
            }

            foreach ($applied as $name) {
                $this->output->line("Migrated: {$name}");
                $this->events->dispatch(new MigrationApplied($name, $connection));
            }
        }

        return 0;
    }
}
