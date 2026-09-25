<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Tests;

use InvalidArgumentException;
use Kinetis\Config\Config;
use Kinetis\Config\Exception\MissingConfigException;
use Kinetis\Console\CommandArguments;
use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\DatabaseBridge\ConnectionFactory;
use Kinetis\Events\EventListenerRegistry;
use Kinetis\Migrations\Console\MigrateCommand;
use Kinetis\Migrations\Console\MigrationContext;
use Kinetis\Migrations\Console\RollbackCommand;
use Kinetis\Migrations\Console\StatusCommand;
use Kinetis\Migrations\Events\MigrationApplied;
use Kinetis\Migrations\Events\MigrationRolledBack;
use Kinetis\Migrations\Exception\MigrationIntegrityException;
use Kinetis\Migrations\Tests\Fixtures\FakeDatabase;
use Kinetis\Migrations\Tests\Fixtures\FakeDatabaseLink;
use Kinetis\Migrations\Tests\Fixtures\MigrationEventRecorder;
use Kinetis\Migrations\Tests\Fixtures\OutputCapture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * migrate, migrate:status and migrate:rollback over a migrations/
 * directory with connection partitions, each connection reaching its own
 * FakeDatabase. The connection's DB_* block is still validated by the
 * real ConnectionFactory, so an unconfigured connection fails the way it
 * does in production.
 */
final class PartitionedCommandsTest extends TestCase
{
    private const array CONFIGURED = [
        'DB_CONNECTION' => 'mysql',
        'DB_PASSWORD' => 'secret',
        'DB_REPORTING_CONNECTION' => 'pgsql',
        'DB_REPORTING_PASSWORD' => 'secret',
        'DB_AUDIT_CONNECTION' => 'mysql',
        'DB_AUDIT_PASSWORD' => 'secret',
    ];

    private const string ROOT = '20260101000000_create_orders';

    private const string REPORTING = '20260101000001_create_reports';

    private string $migrations;

    /** @var array<string, FakeDatabase> */
    private array $databases = [];

    /** @var list<string> every link opened, by connection, in order */
    private array $opened = [];

    private int $open = 0;

    private int $mostOpen = 0;

    private RequestScope $scope;

    private MigrationEventRecorder $recorder;

    protected function setUp(): void
    {
        $this->migrations = \sys_get_temp_dir() . '/kinetis-partitioned-migrations-' . \bin2hex(\random_bytes(8));
        \mkdir($this->migrations);

        foreach (['default', 'reporting', 'audit'] as $connection) {
            $this->databases[$connection] = new FakeDatabase();
        }

        $this->recorder = new MigrationEventRecorder();
        $listeners = new EventListenerRegistry();
        $listeners->register(MigrationEventRecorder::class);

        $app = new AppScope();
        $app->instance(EventListenerRegistry::class, $listeners);
        $app->instance(MigrationEventRecorder::class, $this->recorder);
        $app->boot();
        $this->scope = $app->createRequestScope();
    }

    protected function tearDown(): void
    {
        self::remove($this->migrations);
    }

    public function test_a_default_only_project_runs_one_unlabelled_partition(): void
    {
        $this->migration('', self::ROOT);

        self::assertSame([0, 'Migrated: ' . self::ROOT . "\n"], $this->runMigrate());
        self::assertSame(['default'], $this->opened, 'One partition needs no preflight.');
        self::assertSame([0, '[applied] ' . self::ROOT . "\n"], $this->runStatus());
        self::assertSame([0, 'Rolled back: ' . self::ROOT . "\n"], $this->runRollback());
        self::assertSame([0, "Nothing to roll back.\n"], $this->runRollback());

        self::assertEquals(
            [new MigrationApplied(self::ROOT, 'default'), new MigrationRolledBack(self::ROOT, 'default')],
            $this->recorder->events,
        );
        self::assertSame(['CREATE TABLE ' . self::ROOT, 'DROP TABLE ' . self::ROOT], $this->databases['default']->executed);
        $this->assertLinksNeverOverlapped();
    }

    public function test_migrate_and_status_cover_every_partition_each_on_its_own_database(): void
    {
        $this->migration('reporting', self::REPORTING);
        \mkdir("{$this->migrations}/audit");
        $this->migration('', self::ROOT);
        // Not a partition of its own, and not part of reporting's.
        $this->migration('reporting/archive', '20260101000002_nested');

        self::assertSame([0, "Connection: default\n[pending] " . self::ROOT . "\nConnection: audit\nNo migrations found.\n"
            . "Connection: reporting\n[pending] " . self::REPORTING . "\n"], $this->runStatus());
        $this->opened = [];

        self::assertSame([0, "Connection: default\nMigrated: " . self::ROOT . "\nConnection: audit\nNothing to migrate.\n"
            . "Connection: reporting\nMigrated: " . self::REPORTING . "\n"], $this->runMigrate());
        self::assertSame(['default', 'audit', 'reporting', 'default', 'audit', 'reporting'], $this->opened, 'A full preflight, then the migrate pass.');

        self::assertSame(['CREATE TABLE ' . self::ROOT], $this->databases['default']->executed);
        self::assertSame([self::ROOT], \array_keys($this->databases['default']->ledger));
        self::assertSame(['CREATE TABLE ' . self::REPORTING], $this->databases['reporting']->executed);
        self::assertSame([self::REPORTING], \array_keys($this->databases['reporting']->ledger));
        self::assertSame([], $this->databases['audit']->executed);
        self::assertEquals(
            [new MigrationApplied(self::ROOT, 'default'), new MigrationApplied(self::REPORTING, 'reporting')],
            $this->recorder->events,
        );

        self::assertSame([0, "Connection: default\nNothing to migrate.\nConnection: audit\nNothing to migrate.\n"
            . "Connection: reporting\nNothing to migrate.\n"], $this->runMigrate());
        $this->assertLinksNeverOverlapped();
    }

    /**
     * @param array<string, string|true> $options
     * @param array<string, string> $environment
     */
    #[DataProvider('reportingSelections')]
    public function test_a_selected_connection_narrows_every_command_to_its_database(array $options, array $environment): void
    {
        $this->migration('', self::ROOT);
        $this->migration('reporting', self::REPORTING);

        self::assertSame([0, 'Migrated: ' . self::REPORTING . "\n"], $this->runMigrate($options, $environment));
        self::assertSame([0, '[applied] ' . self::REPORTING . "\n"], $this->runStatus($options, $environment));
        self::assertSame([0, 'Rolled back: ' . self::REPORTING . "\n"], $this->runRollback($options, $environment));

        self::assertSame(['reporting', 'reporting', 'reporting'], $this->opened);
        self::assertSame([], $this->databases['default']->executed);
        self::assertSame(['CREATE TABLE ' . self::REPORTING, 'DROP TABLE ' . self::REPORTING], $this->databases['reporting']->executed);
        self::assertEquals(
            [new MigrationApplied(self::REPORTING, 'reporting'), new MigrationRolledBack(self::REPORTING, 'reporting')],
            $this->recorder->events,
        );
    }

    /** @return iterable<string, array{array<string, string|true>, array<string, string>}> */
    public static function reportingSelections(): iterable
    {
        yield 'flag' => [['connection' => 'reporting'], []];
        yield 'environment' => [[], ['MIGRATE_CONNECTION_NAME' => 'reporting']];
        yield 'flag over environment' => [['connection' => 'reporting'], ['MIGRATE_CONNECTION_NAME' => 'default']];
    }

    public function test_rollback_refuses_to_choose_between_databases(): void
    {
        $this->migration('', self::ROOT);
        $this->migration('reporting', self::REPORTING);
        $this->runMigrate();
        $this->opened = [];

        [$exitCode, $error] = OutputCapture::of(\STDERR, fn (): int => $this->scope->get(RollbackCommand::class)->runIn($this->context(), new CommandArguments([], [])));

        self::assertSame(1, $exitCode);
        self::assertSame("Usage: kinetis migrate:rollback --connection=<name>\nA rollback never spans databases, and migrations/ declares "
            . "the connections default, reporting: --connection=<name> or MIGRATE_CONNECTION_NAME is required.\n", $error);
        self::assertSame([], $this->opened);
        self::assertSame([self::ROOT], \array_keys($this->databases['default']->ledger));
        self::assertSame([self::REPORTING], \array_keys($this->databases['reporting']->ledger));
    }

    public function test_the_preflight_stops_the_run_before_any_up_when_a_later_connection_is_unconfigured(): void
    {
        $this->migration('', self::ROOT);
        $this->migration('reporting', self::REPORTING);
        $config = self::CONFIGURED;
        unset($config['DB_REPORTING_CONNECTION'], $config['DB_REPORTING_PASSWORD']);

        [$failure, $output] = $this->failingMigrate($config);

        self::assertInstanceOf(MissingConfigException::class, $failure);
        self::assertSame('Missing required config value "DB_REPORTING_CONNECTION".', $failure->getMessage());
        self::assertSame("Connection: reporting\n", $output);
        self::assertSame([], $this->databases['default']->executed);
        self::assertSame([], $this->databases['default']->ledger);
        self::assertSame([], $this->recorder->events);
        $this->assertLinksNeverOverlapped();
    }

    /**
     * The integrity exception names the migration alone, so the one line
     * printed before it is what says which database failed the check.
     */
    public function test_a_later_partitions_failed_ledger_check_names_its_connection_before_any_up(): void
    {
        $this->migration('', self::ROOT);
        $this->migration('reporting', self::REPORTING);
        $this->databases['reporting']->ledger[self::REPORTING] = \str_repeat('0', 64);

        [$failure, $output] = $this->failingMigrate();

        self::assertInstanceOf(MigrationIntegrityException::class, $failure);
        self::assertSame("Connection: reporting\n", $output);

        foreach ($this->databases as $database) {
            self::assertSame([], $database->executed);
        }

        self::assertSame([], $this->recorder->events);
        $this->assertLinksNeverOverlapped();
    }

    /**
     * A migration moved from the partition that applied it to another one
     * is missing from the first, whichever way it moved, and the preflight
     * refuses before the second applies it again.
     */
    #[DataProvider('moves')]
    public function test_the_preflight_catches_a_deployed_migration_moved_between_partitions(string $appliedOn, string $movedTo): void
    {
        $path = $this->migration($movedTo === 'default' ? '' : $movedTo, self::REPORTING);
        $this->databases[$appliedOn]->ledger[self::REPORTING] = (string) \hash_file('sha256', $path);
        $this->migration('', self::ROOT);
        $this->migration('reporting', '20260101000004_create_totals');

        [$failure, $output] = $this->failingMigrate();

        self::assertInstanceOf(MigrationIntegrityException::class, $failure);
        self::assertStringContainsString(self::REPORTING, $failure->getMessage());
        self::assertSame("Connection: {$appliedOn}\n", $output);

        foreach ($this->databases as $database) {
            self::assertSame([], $database->executed);
        }

        $this->assertLinksNeverOverlapped();
    }

    /** @return iterable<string, array{string, string}> */
    public static function moves(): iterable
    {
        yield 'from reporting to the root' => ['reporting', 'default'];
        yield 'from the root to reporting' => ['default', 'reporting'];
    }

    public function test_a_failing_partition_stops_the_run_and_earlier_partitions_stay_migrated(): void
    {
        $this->migration('', self::ROOT);
        $this->migration('audit', '20260101000003_fail', fails: true);
        $this->migration('reporting', self::REPORTING);

        [$failure, $output] = $this->failingMigrate();

        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertSame('20260101000003_fail failed', $failure->getMessage());
        self::assertSame("Connection: default\nMigrated: " . self::ROOT . "\nConnection: audit\n", $output);

        self::assertSame([self::ROOT], \array_keys($this->databases['default']->ledger));
        self::assertSame([], $this->databases['audit']->ledger);
        self::assertSame([], $this->databases['reporting']->executed);
        self::assertEquals([new MigrationApplied(self::ROOT, 'default')], $this->recorder->events);
        $this->assertLinksNeverOverlapped();
    }

    public function test_a_bad_layout_is_refused_before_any_configuration_is_read(): void
    {
        $this->migration('', self::ROOT);
        \mkdir("{$this->migrations}/Reporting");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid connection name "Reporting"');

        try {
            $this->runMigrate([], [], []);
        } finally {
            self::assertSame([], $this->opened);
        }
    }

    private function assertLinksNeverOverlapped(): void
    {
        self::assertSame(1, $this->mostOpen, 'At most one link is open at a time.');
        self::assertSame(0, $this->open, 'Every link is closed.');
    }

    /**
     * @param array<string, string|true> $options
     * @param array<string, string> $environment
     * @param array<string, string> $config
     * @return array{int, string}
     */
    private function runMigrate(array $options = [], array $environment = [], array $config = self::CONFIGURED): array
    {
        $context = $this->context($environment + $config);

        return OutputCapture::of(\STDOUT, fn (): int => $this->scope->get(MigrateCommand::class)->runIn($context, new CommandArguments([], $options)));
    }

    /**
     * A plain migrate that must fail.
     *
     * @param array<string, string> $config
     * @return array{Throwable, string} the failure, and what was printed before it
     */
    private function failingMigrate(array $config = self::CONFIGURED): array
    {
        $context = $this->context($config);
        $failure = null;

        [, $output] = OutputCapture::of(\STDOUT, function () use ($context, &$failure): int {
            try {
                return $this->scope->get(MigrateCommand::class)->runIn($context, new CommandArguments([], []));
            } catch (Throwable $e) {
                $failure = $e;

                return 1;
            }
        });

        self::assertInstanceOf(Throwable::class, $failure, 'The run must fail.');

        return [$failure, $output];
    }

    /**
     * @param array<string, string|true> $options
     * @param array<string, string> $environment
     * @return array{int, string}
     */
    private function runStatus(array $options = [], array $environment = []): array
    {
        $context = $this->context($environment + self::CONFIGURED);

        return OutputCapture::of(\STDOUT, static fn (): int => new StatusCommand()->runIn($context, new CommandArguments([], $options)));
    }

    /**
     * @param array<string, string|true> $options
     * @param array<string, string> $environment
     * @return array{int, string}
     */
    private function runRollback(array $options = [], array $environment = []): array
    {
        $context = $this->context($environment + self::CONFIGURED);

        return OutputCapture::of(\STDOUT, fn (): int => $this->scope->get(RollbackCommand::class)->runIn($context, new CommandArguments([], $options)));
    }

    /** @param array<string, string> $config */
    private function context(array $config = self::CONFIGURED): MigrationContext
    {
        return new MigrationContext($this->migrations, new Config($config), function (Config $config, string $connection): FakeDatabaseLink {
            ConnectionFactory::singleSession($config, $connection)->close();
            $this->opened[] = $connection;
            $this->mostOpen = \max($this->mostOpen, ++$this->open);

            return new FakeDatabaseLink($this->databases[$connection], function (): void {
                $this->open--;
            });
        });
    }

    /** @return string the file written */
    private function migration(string $directory, string $name, bool $fails = false): string
    {
        $directory = \rtrim("{$this->migrations}/{$directory}", '/');

        if (!\is_dir($directory)) {
            \mkdir($directory, recursive: true);
        }

        $up = $fails ? "throw new \\RuntimeException('{$name} failed');" : "\$db->execute('CREATE TABLE {$name}');";
        $path = "{$directory}/{$name}.php";
        \file_put_contents($path, <<<PHP
            <?php

            use Kinetis\\Persistence\\Contract\\MysqlLink;
            use Kinetis\\Persistence\\Contract\\PostgresLink;
            use Kinetis\\Migrations\\Migration;

            return new class implements Migration {
                public function up(MysqlLink|PostgresLink \$db): void
                {
                    {$up}
                }

                public function down(MysqlLink|PostgresLink \$db): void
                {
                    \$db->execute('DROP TABLE {$name}');
                }
            };
            PHP);

        return $path;
    }

    private static function remove(string $path): void
    {
        if (\is_dir($path)) {
            foreach (\scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove("{$path}/{$entry}");
                }
            }

            \rmdir($path);
        } elseif (\file_exists($path)) {
            \unlink($path);
        }
    }
}
