<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Tests\Integration;

use Kinetis\Console\CommandArguments;
use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\Migrations\Console\MigrateCommand;
use Kinetis\Migrations\Console\RollbackCommand;
use Kinetis\Migrations\Console\StatusCommand;
use Kinetis\Migrations\Tests\Fixtures\MigrationEventRecorder;
use Kinetis\Migrations\Tests\Fixtures\OutputCapture;
use Kinetis\Events\EventListenerRegistry;
use Kinetis\Migrations\Events\MigrationApplied;
use Kinetis\Migrations\Events\MigrationRolledBack;
use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\Driver\PdoMysqlClient;
use Kinetis\Persistence\Driver\PdoPgsqlClient;
use PHPUnit\Framework\TestCase;

/**
 * migrate, migrate:status and migrate:rollback against a real MySQL, the
 * way bin/kinetis runs them: `bootstrap: false`, the connection read from
 * the process environment, and the project the Composer bin proxy names.
 * What each prints and the events it dispatches are the contract.
 *
 * Environment-gated on MYSQL_HOST, like every other real-backend test in
 * this repository; the reporting partition on Postgres additionally on
 * POSTGRES_HOST.
 */
final class MigrationCommandsTest extends TestCase
{
    private const string MIGRATION = '20260101000000_create_bridge_widgets';

    private const string REPORTING_MIGRATION = '20260101000001_create_bridge_reports';

    private const array ENVIRONMENT = [
        'DB_CONNECTION', 'DB_DRIVER', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'MIGRATE_CONNECTION_NAME',
        'DB_REPORTING_CONNECTION', 'DB_REPORTING_HOST', 'DB_REPORTING_PORT', 'DB_REPORTING_NAME', 'DB_REPORTING_USER', 'DB_REPORTING_PASSWORD',
    ];

    private string $projectRoot;

    private mixed $composerBinDir;

    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    protected function setUp(): void
    {
        $host = \getenv('MYSQL_HOST');

        if ($host === false || $host === '') {
            self::markTestSkipped('MYSQL_HOST is not set — real-backend migration tests are environment-gated.');
        }

        foreach (self::ENVIRONMENT as $name) {
            $this->originalEnvironment[$name] = \getenv($name);
        }

        self::setEnvironment([
            'DB_CONNECTION' => 'mysql',
            // The commands pin PDO whatever the deployment selects.
            'DB_DRIVER' => 'native',
            'DB_HOST' => $host,
            'DB_PORT' => \getenv('MYSQL_PORT') ?: '3306',
            'DB_NAME' => \getenv('MYSQL_DATABASE') ?: 'testdb',
            'DB_USER' => \getenv('MYSQL_USER') ?: 'testuser',
            'DB_PASSWORD' => \getenv('MYSQL_PASSWORD') ?: 'testpass',
            'MIGRATE_CONNECTION_NAME' => false,
            'DB_REPORTING_CONNECTION' => false,
        ]);

        $link = self::client();
        $link->execute('DROP TABLE IF EXISTS bridge_widgets');
        $link->execute('DROP TABLE IF EXISTS bridge_reports');
        $link->execute('DROP TABLE IF EXISTS kinetis_migrations');
        $link->close();

        $this->projectRoot = \sys_get_temp_dir() . '/kinetis-bridge-migrations-' . \bin2hex(\random_bytes(8));
        \mkdir($this->projectRoot . '/migrations', recursive: true);
        \mkdir($this->projectRoot . '/vendor/bin', recursive: true);
        $this->composerBinDir = $GLOBALS['_composer_bin_dir'] ?? null;
        $GLOBALS['_composer_bin_dir'] = $this->projectRoot . '/vendor/bin';
    }

    protected function tearDown(): void
    {
        if (!isset($this->projectRoot)) {
            // setUp() skipped before it changed anything.
            return;
        }

        $GLOBALS['_composer_bin_dir'] = $this->composerBinDir;
        self::setEnvironment($this->originalEnvironment);

        foreach ([...\glob($this->projectRoot . '/migrations/reporting/*.php') ?: [], ...\glob($this->projectRoot . '/migrations/*.php') ?: []] as $file) {
            \unlink($file);
        }

        if (\is_dir($this->projectRoot . '/migrations/reporting')) {
            \rmdir($this->projectRoot . '/migrations/reporting');
        }

        \rmdir($this->projectRoot . '/migrations');
        \rmdir($this->projectRoot . '/vendor/bin');
        \rmdir($this->projectRoot . '/vendor');
        \rmdir($this->projectRoot);
    }

    public function test_the_commands_print_and_dispatch_what_they_did(): void
    {
        [$scope, $recorder] = self::commandScope();

        self::assertSame([0, "No migrations found.\n"], self::runStatus($scope));
        self::assertSame([0, "Nothing to migrate.\n"], self::runMigrate($scope));
        self::assertSame([0, "Nothing to roll back.\n"], self::runRollback($scope));

        $this->writeMigration();

        self::assertSame([0, '[pending] ' . self::MIGRATION . "\n"], self::runStatus($scope));
        self::assertSame([0, 'Migrated: ' . self::MIGRATION . "\n"], self::runMigrate($scope));
        self::assertSame([0, '[applied] ' . self::MIGRATION . "\n"], self::runStatus($scope));
        self::assertSame([0, "Nothing to migrate.\n"], self::runMigrate($scope));
        self::assertSame([0, 'Rolled back: ' . self::MIGRATION . "\n"], self::runRollback($scope));
        self::assertSame([0, '[pending] ' . self::MIGRATION . "\n"], self::runStatus($scope));

        self::assertEquals(
            [new MigrationApplied(self::MIGRATION, 'default'), new MigrationRolledBack(self::MIGRATION, 'default')],
            $recorder->events,
        );
    }

    /**
     * The root partition migrates the default MySQL database and
     * migrations/reporting/ the Postgres one, and neither migration nor
     * ledger row reaches the other database.
     */
    public function test_each_partition_migrates_its_own_database(): void
    {
        $host = \getenv('POSTGRES_HOST');

        if ($host === false || $host === '') {
            self::markTestSkipped('POSTGRES_HOST is not set — the reporting partition needs a real Postgres.');
        }

        self::setEnvironment([
            'DB_REPORTING_CONNECTION' => 'pgsql',
            'DB_REPORTING_HOST' => $host,
            'DB_REPORTING_PORT' => \getenv('POSTGRES_PORT') ?: '5432',
            'DB_REPORTING_NAME' => \getenv('POSTGRES_DATABASE') ?: 'testdb',
            'DB_REPORTING_USER' => \getenv('POSTGRES_USER') ?: 'testuser',
            'DB_REPORTING_PASSWORD' => \getenv('POSTGRES_PASSWORD') ?: 'testpass',
        ]);

        $postgres = self::postgres();
        $postgres->execute('DROP TABLE IF EXISTS bridge_widgets');
        $postgres->execute('DROP TABLE IF EXISTS bridge_reports');
        $postgres->execute('DROP TABLE IF EXISTS kinetis_migrations');

        [$scope, $recorder] = self::commandScope();
        $this->writeMigration();
        $this->writeMigration('reporting', self::REPORTING_MIGRATION, 'bridge_reports');

        self::assertSame(
            [0, "Connection: default\nMigrated: " . self::MIGRATION . "\nConnection: reporting\nMigrated: " . self::REPORTING_MIGRATION . "\n"],
            self::runMigrate($scope),
        );

        $mysql = self::client();
        self::assertSame([self::MIGRATION], self::column($mysql, 'SELECT migration FROM kinetis_migrations'));
        self::assertSame([], self::column($mysql, "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'bridge_reports'"));
        self::assertSame([self::REPORTING_MIGRATION], self::column($postgres, 'SELECT migration FROM kinetis_migrations'));
        self::assertSame([], self::column($postgres, "SELECT table_name FROM information_schema.tables WHERE table_name = 'bridge_widgets'"));

        // Without a selection, a rollback refuses to pick a database.
        self::assertSame(1, OutputCapture::of(\STDERR, static fn (): int => $scope->get(RollbackCommand::class)->run(new CommandArguments([], [])))[0]);
        self::assertSame(
            [0, 'Rolled back: ' . self::REPORTING_MIGRATION . "\n"],
            OutputCapture::of(\STDOUT, static fn (): int => $scope->get(RollbackCommand::class)->run(new CommandArguments([], ['connection' => 'reporting']))),
        );

        self::assertSame([self::MIGRATION], self::column($mysql, 'SELECT migration FROM kinetis_migrations'));
        self::assertSame([], self::column($postgres, 'SELECT migration FROM kinetis_migrations'));
        self::assertEquals(
            [
                new MigrationApplied(self::MIGRATION, 'default'),
                new MigrationApplied(self::REPORTING_MIGRATION, 'reporting'),
                new MigrationRolledBack(self::REPORTING_MIGRATION, 'reporting'),
            ],
            $recorder->events,
        );

        $mysql->execute('DROP TABLE bridge_widgets');
        $mysql->close();
        $postgres->execute('DROP TABLE IF EXISTS kinetis_migrations');
        $postgres->close();
    }

    /** @return array{int, string} */
    private static function runStatus(RequestScope $scope): array
    {
        return OutputCapture::of(\STDOUT, static fn (): int => $scope->get(StatusCommand::class)->run(new CommandArguments([], [])));
    }

    /** @return array{int, string} */
    private static function runMigrate(RequestScope $scope): array
    {
        return OutputCapture::of(\STDOUT, static fn (): int => $scope->get(MigrateCommand::class)->run(new CommandArguments([], [])));
    }

    /** @return array{int, string} */
    private static function runRollback(RequestScope $scope): array
    {
        return OutputCapture::of(\STDOUT, static fn (): int => $scope->get(RollbackCommand::class)->run(new CommandArguments([], [])));
    }

    /**
     * The container bin/kinetis resolves a command from, with the
     * discovered listener registry bound the way BootSequence binds it.
     *
     * @return array{RequestScope, MigrationEventRecorder}
     */
    private static function commandScope(): array
    {
        $recorder = new MigrationEventRecorder();
        $listeners = new EventListenerRegistry();
        $listeners->register(MigrationEventRecorder::class);

        $app = new AppScope();
        $app->instance(EventListenerRegistry::class, $listeners);
        $app->instance(MigrationEventRecorder::class, $recorder);
        $app->boot();

        return [$app->createRequestScope(), $recorder];
    }

    private function writeMigration(string $directory = '', string $name = self::MIGRATION, string $table = 'bridge_widgets'): void
    {
        $directory = \rtrim($this->projectRoot . '/migrations/' . $directory, '/');

        if (!\is_dir($directory)) {
            \mkdir($directory);
        }

        \file_put_contents("{$directory}/{$name}.php", \str_replace('bridge_widgets', $table, <<<'PHP'
            <?php

            declare(strict_types=1);

            use Kinetis\Persistence\Contract\MysqlLink;
            use Kinetis\Persistence\Contract\PostgresLink;
            use Kinetis\Migrations\Migration;

            return new class implements Migration
            {
                public function up(MysqlLink|PostgresLink $db): void
                {
                    $db->execute('CREATE TABLE bridge_widgets (id INT PRIMARY KEY)');
                }

                public function down(MysqlLink|PostgresLink $db): void
                {
                    $db->execute('DROP TABLE bridge_widgets');
                }
            };
            PHP));
    }

    /** @param array<string, string|false> $variables false unsets */
    private static function setEnvironment(array $variables): void
    {
        foreach ($variables as $name => $value) {
            \putenv($value === false ? $name : "{$name}={$value}");
        }
    }

    /** @return list<mixed> */
    private static function column(SqlLink $link, string $sql): array
    {
        $values = [];

        foreach ($link->execute($sql) as $row) {
            $values[] = \array_values($row)[0];
        }

        return $values;
    }

    private static function postgres(): SqlLink
    {
        return new PdoPgsqlClient(
            (string) \getenv('DB_REPORTING_HOST'),
            (string) \getenv('DB_REPORTING_USER'),
            (string) \getenv('DB_REPORTING_PASSWORD'),
            (string) \getenv('DB_REPORTING_NAME'),
            (int) \getenv('DB_REPORTING_PORT'),
        );
    }

    private static function client(): SqlLink
    {
        return new PdoMysqlClient(
            (string) \getenv('DB_HOST'),
            (string) \getenv('DB_USER'),
            (string) \getenv('DB_PASSWORD'),
            (string) \getenv('DB_NAME'),
            (int) \getenv('DB_PORT'),
        );
    }
}
