<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Console;

use Kinetis\Config\Config;
use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\Console\Output;
use Kinetis\Migrations\MigrationScaffolder;
use Kinetis\Runtime\ProjectRoot;

final readonly class MakeCommand
{
    public function __construct(
        private Output $output,
        private ProjectRoot $projectRoot,
        private Config $config,
    ) {}

    #[Command('migrate:make', description: 'Scaffold a migration file: migrate:make <description>. --connection=<name> writes it to migrations/<name>/.', bootstrap: false)]
    public function run(CommandArguments $arguments): int
    {
        $description = $arguments->get(0);

        if ($description === null) {
            $this->output->error('Usage: kinetis migrate:make <description>');

            return 1;
        }

        $path = MigrationScaffolder::create(MigrationContext::forProject($this->projectRoot, $this->config)->scaffoldPath($arguments), $description);
        $this->output->line("Created {$path}");

        return 0;
    }
}
