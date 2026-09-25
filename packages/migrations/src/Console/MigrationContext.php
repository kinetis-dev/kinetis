<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Console;

use Closure;
use InvalidArgumentException;
use Kinetis\Config\Config;
use Kinetis\Console\CommandArguments;
use Kinetis\DatabaseBridge\ConnectionFactory;
use Kinetis\Migrations\MigrationRunner;
use Kinetis\Migrations\SqlMigrationRepository;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Runtime\ProjectRoot;
use RuntimeException;

/**
 * The shared construction behind the migrate:* commands: which
 * connection partitions of the project's migrations/ directory a command
 * covers, and a MigrationRunner over each partition's own connection.
 *
 * migrations/*.php belongs to the default connection, and each direct
 * child directory migrations/<name>/ to the connection of that name.
 * --connection=<name> selects one partition, then a non-empty
 * MIGRATE_CONNECTION_NAME; with neither, every partition is selected,
 * default first.
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
    /**
     * No uppercase and no underscore, so no two names derive the same
     * DB_{NAME}_* keys — kinetis/orm's connection-name grammar.
     */
    private const string CONNECTION = '/^[a-z][a-z0-9]*$/D';

    private const string GRAMMAR = 'a connection name is lowercase ASCII letters and digits, starting with a letter (^[a-z][a-z0-9]*$)';

    /**
     * @param Closure(Config, string): (MysqlLink|PostgresLink) $connect
     *     builds the one-session link for a connection name
     */
    public function __construct(
        private string $migrationsPath,
        private Config $config,
        private Closure $connect,
    ) {}

    public static function detect(): self
    {
        // One session for the whole run, whatever DB_DRIVER says: the
        // advisory lock is scoped to that session, so a client that
        // pooled — or that replaced a discarded session — would run
        // migrations without holding it. These commands are strictly
        // serial, and blocking on a query costs them nothing.
        return new self(
            ProjectRoot::detect(__DIR__ . '/..') . '/migrations',
            Config::fromEnvironment(),
            ConnectionFactory::singleSession(...),
        );
    }

    /**
     * The directory migrate:make writes into: the connection
     * --connection names, otherwise the default one. MIGRATE_CONNECTION_NAME
     * does not move where a migration is written.
     */
    public function scaffoldPath(CommandArguments $arguments): string
    {
        return $this->pathOf($this->selected($arguments) ?? 'default');
    }

    /**
     * Connection name => migrations directory for every partition the
     * command covers: the selected one, or all of them — default first,
     * then the named directories in byte order.
     *
     * @return non-empty-array<string, string>
     */
    public function partitions(CommandArguments $arguments): array
    {
        $selected = $this->selected($arguments) ?? $this->environmentSelection();
        // Read even when one connection is selected: a malformed layout
        // is refused whichever partition the command targets.
        $named = $this->namedConnections();

        if ($selected !== null) {
            return [$selected => $this->pathOf($selected)];
        }

        $partitions = ['default' => $this->migrationsPath];

        foreach ($named as $name) {
            $partitions[$name] = $this->pathOf($name);
        }

        return $partitions;
    }

    /**
     * Runs $operation on a runner over $connection's own link, closing
     * the link afterwards whether or not it succeeded, so a command never
     * holds two.
     *
     * @template T
     * @param callable(MigrationRunner): T $operation
     * @return T
     */
    public function run(string $connection, string $path, callable $operation): mixed
    {
        $db = ($this->connect)($this->config, $connection);

        try {
            return $operation(new MigrationRunner($db, new SqlMigrationRepository($db), $path));
        } finally {
            $db->close();
        }
    }

    private function selected(CommandArguments $arguments): ?string
    {
        if (!$arguments->hasOption('connection')) {
            return null;
        }

        $name = $arguments->option('connection')
            ?? throw new InvalidArgumentException('--connection needs a value: --connection=<name>.');

        return self::validated($name, '--connection');
    }

    private function environmentSelection(): ?string
    {
        $name = $this->config->get('MIGRATE_CONNECTION_NAME');

        return $name === null || $name === '' ? null : self::validated($name, 'MIGRATE_CONNECTION_NAME');
    }

    private function pathOf(string $connection): string
    {
        return $connection === 'default' ? $this->migrationsPath : "{$this->migrationsPath}/{$connection}";
    }

    /**
     * Every direct child directory is a connection partition; one whose
     * name no connection can have is refused rather than skipped, since
     * skipping it would leave that database unmigrated.
     *
     * @return list<string>
     */
    private function namedConnections(): array
    {
        if (!is_dir($this->migrationsPath)) {
            return [];
        }

        $entries = @scandir($this->migrationsPath)
            ?: throw new RuntimeException("Cannot read the migrations directory {$this->migrationsPath}.");
        $names = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir("{$this->migrationsPath}/{$entry}")) {
                continue;
            }

            if ($entry === 'default') {
                throw new InvalidArgumentException(
                    "The migrations directory {$this->migrationsPath}/default is refused: the default connection's "
                    . "migrations are the files directly in {$this->migrationsPath}.",
                );
            }

            $names[] = self::validated($entry, "the directory {$this->migrationsPath}/{$entry}");
        }

        sort($names, SORT_STRING);

        return $names;
    }

    private static function validated(string $name, string $source): string
    {
        if (preg_match(self::CONNECTION, $name) !== 1) {
            throw new InvalidArgumentException("Invalid connection name \"{$name}\" from {$source}: " . self::GRAMMAR . '.');
        }

        if ($name === 'app') {
            throw new InvalidArgumentException(
                "The connection name \"app\" from {$source} is reserved: its DB_NAME key, DB_APP_NAME, "
                . 'is also the default connection\'s DB_APP_NAME.',
            );
        }

        return $name;
    }
}
