<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Console;

use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\Events\EventDispatcher;
use Kinetis\Migrations\Events\MigrationRolledBack;

final readonly class RollbackCommand
{
    public function __construct(
        private EventDispatcher $events,
    ) {}

    #[Command('migrate:rollback', description: 'Roll back the most recent migration. --connection=<name> targets a named DB_* block.', bootstrap: false)]
    public function run(CommandArguments $arguments): int
    {
        $name = MigrationContext::detect()->runner($arguments)->rollback();

        if ($name !== null) {
            fwrite(STDOUT, "Rolled back: {$name}\n");
            $this->events->dispatch(new MigrationRolledBack($name));
        } else {
            fwrite(STDOUT, "Nothing to roll back.\n");
        }

        return 0;
    }
}
