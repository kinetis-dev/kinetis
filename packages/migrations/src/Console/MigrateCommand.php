<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Console;

use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\Events\EventDispatcher;
use Kinetis\Migrations\Events\MigrationApplied;

final readonly class MigrateCommand
{
    public function __construct(
        private EventDispatcher $events,
    ) {}

    #[Command('migrate', description: 'Apply pending migrations. --connection=<name> targets a named DB_* block.', bootstrap: false)]
    public function run(CommandArguments $arguments): int
    {
        $applied = MigrationContext::detect()->runner($arguments)->migrate();

        if ($applied === []) {
            fwrite(STDOUT, "Nothing to migrate.\n");

            return 0;
        }

        foreach ($applied as $name) {
            fwrite(STDOUT, "Migrated: {$name}\n");
            $this->events->dispatch(new MigrationApplied($name));
        }

        return 0;
    }
}
