<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Console;

use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\Migrations\MigrationScaffolder;

final readonly class MakeCommand
{
    #[Command('migrate:make', description: 'Scaffold a migration file: migrate:make <description>', bootstrap: false)]
    public function run(CommandArguments $arguments): int
    {
        $description = $arguments->get(0);

        if ($description === null) {
            fwrite(STDERR, "Usage: kinetis migrate:make <description>\n");

            return 1;
        }

        $path = MigrationScaffolder::create(MigrationContext::detect()->migrationsPath, $description);
        fwrite(STDOUT, "Created {$path}\n");

        return 0;
    }
}
