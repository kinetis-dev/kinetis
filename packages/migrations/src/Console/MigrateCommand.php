<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Console;

use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\Events\EventDispatcher;
use Kinetis\Migrations\Events\MigrationApplied;
use Kinetis\Migrations\MigrationRunner;
use Throwable;

final readonly class MigrateCommand
{
    public function __construct(
        private EventDispatcher $events,
    ) {}

    #[Command('migrate', description: 'Apply pending migrations on every connection partition, default first. --connection=<name> migrates one.', bootstrap: false)]
    public function run(CommandArguments $arguments): int
    {
        return $this->runIn(MigrationContext::detect(), $arguments);
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
            // exception itself may not.
            foreach ($partitions as $connection => $path) {
                try {
                    $context->run($connection, $path, static fn (MigrationRunner $runner): array => $runner->status());
                } catch (Throwable $e) {
                    fwrite(STDOUT, "Connection: {$connection}\n");

                    throw $e;
                }
            }
        }

        foreach ($partitions as $connection => $path) {
            if ($several) {
                fwrite(STDOUT, "Connection: {$connection}\n");
            }

            $applied = $context->run($connection, $path, static fn (MigrationRunner $runner): array => $runner->migrate());

            if ($applied === []) {
                fwrite(STDOUT, "Nothing to migrate.\n");

                continue;
            }

            foreach ($applied as $name) {
                fwrite(STDOUT, "Migrated: {$name}\n");
                $this->events->dispatch(new MigrationApplied($name, $connection));
            }
        }

        return 0;
    }
}
