<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Console;

use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\Events\EventDispatcher;
use Kinetis\Migrations\Events\MigrationRolledBack;
use Kinetis\Migrations\MigrationRunner;

final readonly class RollbackCommand
{
    public function __construct(
        private EventDispatcher $events,
    ) {}

    #[Command('migrate:rollback', description: 'Roll back the most recent migration on one connection. --connection=<name> selects it, and is required when migrations/ has connection directories.', bootstrap: false)]
    public function run(CommandArguments $arguments): int
    {
        return $this->runIn(MigrationContext::detect(), $arguments);
    }

    /**
     * A rollback never spans databases: with connection directories
     * declared and no connection selected, it refuses rather than pick
     * one.
     *
     * @internal
     */
    public function runIn(MigrationContext $context, CommandArguments $arguments): int
    {
        $partitions = $context->partitions($arguments);

        if (\count($partitions) > 1) {
            fwrite(STDERR, "Usage: kinetis migrate:rollback --connection=<name>\n"
                . 'A rollback never spans databases, and migrations/ declares the connections '
                . implode(', ', array_keys($partitions)) . ": --connection=<name> or MIGRATE_CONNECTION_NAME is required.\n");

            return 1;
        }

        $connection = array_key_first($partitions);
        $name = $context->run($connection, $partitions[$connection], static fn (MigrationRunner $runner): ?string => $runner->rollback());

        if ($name !== null) {
            fwrite(STDOUT, "Rolled back: {$name}\n");
            $this->events->dispatch(new MigrationRolledBack($name, $connection));
        } else {
            fwrite(STDOUT, "Nothing to roll back.\n");
        }

        return 0;
    }
}
