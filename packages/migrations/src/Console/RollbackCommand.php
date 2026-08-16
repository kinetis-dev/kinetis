<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Console;

use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;

final readonly class RollbackCommand
{
    #[Command('migrate:rollback', description: 'Roll back the most recent migration. --connection=<name> targets a named DB_* block.', bootstrap: false)]
    public function run(CommandArguments $arguments): int
    {
        $name = MigrationContext::detect()->runner($arguments)->rollback();

        fwrite(STDOUT, $name !== null ? "Rolled back: {$name}\n" : "Nothing to roll back.\n");

        return 0;
    }
}
