<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Console;

use Kinetis\Config\Config;
use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\Console\Output;
use Kinetis\Events\EventDispatcher;
use Kinetis\Migrations\Events\MigrationRolledBack;
use Kinetis\Migrations\MigrationRunner;
use Kinetis\Runtime\ProjectRoot;

final readonly class RollbackCommand
{
    public function __construct(
        private EventDispatcher $events,
        private Output $output,
        private ProjectRoot $projectRoot,
        private Config $config,
    ) {}

    #[Command('migrate:rollback', description: 'Roll back the most recent migration on one connection. --connection=<name> selects it, and is required when migrations/ has connection directories.', bootstrap: false)]
    public function run(CommandArguments $arguments): int
    {
        return $this->runIn(MigrationContext::forProject($this->projectRoot, $this->config), $arguments);
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
            $this->output->error('Usage: kinetis migrate:rollback --connection=<name>');
            $this->output->error('A rollback never spans databases, and migrations/ declares the connections '
                . implode(', ', array_keys($partitions)) . ': --connection=<name> or MIGRATE_CONNECTION_NAME is required.');

            return 1;
        }

        $connection = array_key_first($partitions);
        $name = $context->run($connection, $partitions[$connection], static fn (MigrationRunner $runner): ?string => $runner->rollback());

        if ($name !== null) {
            $this->output->line("Rolled back: {$name}");
            $this->events->dispatch(new MigrationRolledBack($name, $connection));
        } else {
            $this->output->line('Nothing to roll back.');
        }

        return 0;
    }
}
