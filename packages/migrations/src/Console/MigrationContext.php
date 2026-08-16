<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Console;

use Kinetis\Config\Config;
use Kinetis\Console\CommandArguments;
use Kinetis\Migrations\MigrationRunner;
use Kinetis\Persistence\SqlConnectionFactory;
use Kinetis\Migrations\SqlMigrationRepository;
use Kinetis\Runtime\ProjectRoot;

/**
 * The shared construction behind the migrate:* commands: the project's
 * migrations/ directory, and a MigrationRunner against the connection
 * --connection selects (falling back to the MIGRATE_CONNECTION_NAME
 * environment key, then 'default' — the explicit flag wins, the same
 * precedence explicit $poolOptions have over DB_* keys).
 *
 * These commands declare `bootstrap: false`: they read DB_* directly
 * and need none of the application's own wiring, so they run in bare
 * contexts (CI, an init container) with nothing but environment
 * variables.
 *
 * @internal
 */
final readonly class MigrationContext
{
    public function __construct(
        public string $migrationsPath,
        private Config $config,
    ) {}

    public static function detect(): self
    {
        return new self(ProjectRoot::detect(__DIR__ . '/..') . '/migrations', Config::fromEnvironment());
    }

    public function runner(CommandArguments $arguments): MigrationRunner
    {
        $connectionName = $arguments->option('connection')
            ?? $this->config->string('MIGRATE_CONNECTION_NAME', 'default');

        $db = SqlConnectionFactory::fromConfig($this->config, $connectionName);

        return new MigrationRunner($db, new SqlMigrationRepository($db), $this->migrationsPath);
    }
}
