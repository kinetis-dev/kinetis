<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Console;

use Kinetis\Config\Config;
use Kinetis\Console\CommandArguments;
use Kinetis\Migrations\MigrationRunner;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
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

        $db = $this->connection($connectionName);

        return new MigrationRunner($db, new SqlMigrationRepository($db), $this->migrationsPath);
    }

    /**
     * Extracted from runner() as its own private seam — reached
     * directly via reflection in tests, the same established pattern
     * this project already uses for a class's own pure decision logic
     * elsewhere (Kinetis\Storage\AmpFileAdapter's resolveCopyVisibility()/
     * populateTempStream(), for one), rather than made public purely to
     * be callable from a test with no supported reason for an
     * application caller to bypass runner() and reach it directly. The
     * migrate:* commands are strictly serial and gain nothing from a
     * pooled native connection, and a wider pool risks MigrationRunner's
     * own advisory-lock acquire/release calls landing on two different
     * physical connections — see MigrationRunner's own class docblock
     * for the invariant this enforces on its behalf. Forces
     * maxConnections: 1 unconditionally, regardless of
     * DB_MAX_CONNECTIONS — the same explicit-poolOption-wins-over-env-key
     * precedent SqlConnectionFactory::fromConfig() itself already
     * documents.
     */
    private function connection(string $connectionName): MysqlLink|PostgresLink
    {
        return SqlConnectionFactory::fromConfig($this->config, $connectionName, ['maxConnections' => 1]);
    }
}
