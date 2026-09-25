<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Tests;

use InvalidArgumentException;
use Kinetis\Config\Config;
use Kinetis\Console\CommandArguments;
use Kinetis\DatabaseBridge\TelemetrySqlInstrumentation;
use Kinetis\Migrations\Console\MigrationContext;
use Kinetis\Migrations\MigrationRunner;
use Kinetis\Migrations\Tests\Fixtures\FakeDatabase;
use Kinetis\Migrations\Tests\Fixtures\FakeDatabaseLink;
use Kinetis\Persistence\Driver\PdoMysqlClient;
use Kinetis\Persistence\Driver\PdoPgsqlClient;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Which partitions of migrations/ a command covers, and the link each
 * one runs on.
 *
 * The migrate:* commands always run on a single-session PDO client,
 * whatever DB_DRIVER a deployment sets: the advisory lock
 * MigrationRunner holds is scoped to the database session, and only a
 * client that never replaces that session keeps the whole run on the one
 * holding the lock.
 *
 * Nothing here reaches a database. Every driver the factory can build
 * validates its options at construction and connects lazily on first
 * use. What losing that session costs a run is
 * {@see Integration\MigrationSessionLossTest}.
 */
final class MigrationContextTest extends TestCase
{
    private const array ENVIRONMENT = ['DB_CONNECTION', 'DB_DRIVER', 'DB_PASSWORD', 'MIGRATE_CONNECTION_NAME'];

    private const string GRAMMAR = 'a connection name is lowercase ASCII letters and digits, starting with a letter (^[a-z][a-z0-9]*$).';

    private string $projectRoot;

    private string $migrations;

    protected function setUp(): void
    {
        $this->projectRoot = \sys_get_temp_dir() . '/kinetis-migration-context-' . \bin2hex(\random_bytes(8));
        $this->migrations = $this->projectRoot . '/migrations';
        \mkdir($this->projectRoot);
    }

    protected function tearDown(): void
    {
        self::remove($this->projectRoot);
    }

    /** @return iterable<string, array{string, class-string}> */
    public static function dialects(): iterable
    {
        yield 'mysql' => ['mysql', PdoMysqlClient::class];
        yield 'pgsql' => ['pgsql', PdoPgsqlClient::class];
    }

    /**
     * The link detect() builds comes from kinetis/database-bridge's
     * single-session policy, which reports it through Kinetis telemetry,
     * and is closed once the runner is done with it.
     *
     * @param class-string $expected
     */
    #[DataProvider('dialects')]
    public function test_detect_runs_on_a_single_session_pdo_client_even_under_db_driver_native(string $dialect, string $expected): void
    {
        $original = [];

        foreach (self::ENVIRONMENT as $name) {
            $original[$name] = \getenv($name);
        }

        $composerBinDir = $GLOBALS['_composer_bin_dir'] ?? null;
        $GLOBALS['_composer_bin_dir'] = $this->projectRoot . '/vendor/bin';
        self::setEnvironment(['DB_CONNECTION' => $dialect, 'DB_DRIVER' => 'native', 'DB_PASSWORD' => 'secret', 'MIGRATE_CONNECTION_NAME' => false]);

        try {
            $context = MigrationContext::detect();
            $link = $context->run('default', $this->migrations, static fn (MigrationRunner $runner): object => self::linkOf($runner));
            self::assertSame(['default' => $this->migrations], $context->partitions(new CommandArguments([], [])));
        } finally {
            $GLOBALS['_composer_bin_dir'] = $composerBinDir;
            self::setEnvironment($original);
        }

        self::assertInstanceOf($expected, $link);
        self::assertTrue(self::property($link, 'singleSession'));
        self::assertInstanceOf(
            TelemetrySqlInstrumentation::class,
            self::property(self::property($link, 'instrumentation'), 'instrumentation'),
        );
        self::assertTrue($link->isClosed());
    }

    public function test_run_closes_the_link_when_the_operation_throws(): void
    {
        $link = new FakeDatabaseLink(new FakeDatabase(), static function (): void {});
        $context = new MigrationContext($this->migrations, new Config([]), static fn (): FakeDatabaseLink => $link);

        try {
            $context->run('default', $this->migrations, static fn (): never => throw new LogicException('operation failed'));
            self::fail('The operation\'s failure must propagate.');
        } catch (LogicException $e) {
            self::assertSame('operation failed', $e->getMessage());
        }

        self::assertTrue($link->isClosed());
    }

    public function test_a_project_without_connection_directories_has_the_default_partition_alone(): void
    {
        self::assertSame(['default' => $this->migrations], $this->partitions());

        $this->file('20260101000000_a.php');
        $this->file('notes.txt');

        self::assertSame(['default' => $this->migrations], $this->partitions());
    }

    public function test_connection_directories_follow_default_in_byte_order(): void
    {
        foreach (['zeta', 'b2', 'alpha', 'b10'] as $name) {
            \mkdir("{$this->migrations}/{$name}", recursive: true);
        }

        self::assertSame(
            ['default', 'alpha', 'b10', 'b2', 'zeta'],
            \array_keys($this->partitions()),
        );
        self::assertSame("{$this->migrations}/b10", $this->partitions()['b10']);
    }

    /**
     * @param array<string, string|true> $options
     * @param array<string, string> $config
     * @param array<string, string> $expected relative to migrations/
     */
    #[DataProvider('selections')]
    public function test_a_selected_connection_narrows_the_partitions(array $options, array $config, array $expected): void
    {
        \mkdir("{$this->migrations}/reporting", recursive: true);
        \mkdir("{$this->migrations}/audit");

        $partitions = $this->partitions($options, $config);

        self::assertSame(
            \array_map(fn (string $path): string => \rtrim("{$this->migrations}/{$path}", '/'), $expected),
            $partitions,
        );
    }

    /** @return iterable<string, array{array<string, string|true>, array<string, string>, array<string, string>}> */
    public static function selections(): iterable
    {
        yield 'none' => [[], [], ['default' => '', 'audit' => 'audit', 'reporting' => 'reporting']];
        yield 'empty environment' => [[], ['MIGRATE_CONNECTION_NAME' => ''], ['default' => '', 'audit' => 'audit', 'reporting' => 'reporting']];
        yield 'flag' => [['connection' => 'reporting'], [], ['reporting' => 'reporting']];
        yield 'flag naming default' => [['connection' => 'default'], [], ['default' => '']];
        yield 'environment' => [[], ['MIGRATE_CONNECTION_NAME' => 'audit'], ['audit' => 'audit']];
        yield 'flag over environment' => [['connection' => 'default'], ['MIGRATE_CONNECTION_NAME' => 'audit'], ['default' => '']];
        yield 'a connection without a directory yet' => [['connection' => 'archive'], [], ['archive' => 'archive']];
    }

    /**
     * @param array<string, string|true> $options
     * @param array<string, string> $config
     */
    #[DataProvider('refusals')]
    public function test_a_bad_selector_or_layout_is_refused(?string $directory, array $options, array $config, string $message): void
    {
        if ($directory !== null) {
            \mkdir("{$this->migrations}/{$directory}", recursive: true);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(\str_replace('{migrations}', $this->migrations, $message));

        $this->partitions($options, $config);
    }

    /** @return iterable<string, array{?string, array<string, string|true>, array<string, string>, string}> */
    public static function refusals(): iterable
    {
        yield 'bare flag, even with the environment set' => [null, ['connection' => true], ['MIGRATE_CONNECTION_NAME' => 'reporting'], '--connection needs a value: --connection=<name>.'];
        yield 'empty flag' => [null, ['connection' => ''], [], 'Invalid connection name "" from --connection: ' . self::GRAMMAR];
        yield 'uppercase flag' => [null, ['connection' => 'Reporting'], [], 'Invalid connection name "Reporting" from --connection: ' . self::GRAMMAR];
        yield 'reserved flag' => [null, ['connection' => 'app'], [], 'The connection name "app" from --connection is reserved: its DB_NAME key, DB_APP_NAME, is also the default connection\'s DB_APP_NAME.'];
        yield 'underscored environment' => [null, [], ['MIGRATE_CONNECTION_NAME' => 'report_ing'], 'Invalid connection name "report_ing" from MIGRATE_CONNECTION_NAME: ' . self::GRAMMAR];
        yield 'uppercase directory' => ['Reporting', [], [], 'Invalid connection name "Reporting" from the directory {migrations}/Reporting: ' . self::GRAMMAR];
        yield 'reserved directory' => ['app', [], [], 'The connection name "app" from the directory {migrations}/app is reserved'];
        yield 'default directory' => ['default', [], [], 'The migrations directory {migrations}/default is refused: the default connection\'s migrations are the files directly in {migrations}.'];
        yield 'bad directory under a valid selection' => ['2024', ['connection' => 'default'], [], 'Invalid connection name "2024" from the directory {migrations}/2024: ' . self::GRAMMAR];
    }

    public function test_make_writes_to_the_selected_connection_and_ignores_the_environment(): void
    {
        $context = new MigrationContext($this->migrations, new Config(['MIGRATE_CONNECTION_NAME' => 'reporting']), static fn (): never => throw new LogicException('make never connects'));

        self::assertSame($this->migrations, $context->scaffoldPath(new CommandArguments([], [])));
        self::assertSame($this->migrations, $context->scaffoldPath(new CommandArguments([], ['connection' => 'default'])));
        self::assertSame("{$this->migrations}/audit", $context->scaffoldPath(new CommandArguments([], ['connection' => 'audit'])));
    }

    /**
     * @param array<string, string|true> $options
     * @param array<string, string> $config
     * @return array<string, string>
     */
    private function partitions(array $options = [], array $config = []): array
    {
        $context = new MigrationContext($this->migrations, new Config($config), static fn (): never => throw new LogicException('partitions never connect'));

        return $context->partitions(new CommandArguments([], $options));
    }

    private function file(string $name): void
    {
        if (!\is_dir($this->migrations)) {
            \mkdir($this->migrations);
        }

        \file_put_contents("{$this->migrations}/{$name}", '<?php');
    }

    /** @param array<string, string|false> $variables false unsets */
    private static function setEnvironment(array $variables): void
    {
        foreach ($variables as $name => $value) {
            \putenv($value === false ? $name : "{$name}={$value}");
        }
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

    private static function linkOf(MigrationRunner $runner): object
    {
        $link = self::property($runner, 'db');
        \assert(\is_object($link));

        return $link;
    }

    private static function property(object $object, string $name): mixed
    {
        return new ReflectionProperty($object, $name)->getValue($object);
    }
}
