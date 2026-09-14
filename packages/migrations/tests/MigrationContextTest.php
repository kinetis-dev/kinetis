<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Tests;

use Kinetis\Config\Config;
use Kinetis\Console\CommandArguments;
use Kinetis\DatabaseBridge\TelemetrySqlInstrumentation;
use Kinetis\Migrations\Console\MigrationContext;
use Kinetis\Migrations\MigrationRunner;
use Kinetis\Persistence\Driver\PdoMysqlClient;
use Kinetis\Persistence\Driver\PdoPgsqlClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
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
    /** @return iterable<string, array{string, class-string}> */
    public static function dialects(): iterable
    {
        yield 'mysql' => ['mysql', PdoMysqlClient::class];
        yield 'pgsql' => ['pgsql', PdoPgsqlClient::class];
    }

    /** @param class-string $expected */
    #[DataProvider('dialects')]
    public function test_the_runner_gets_a_single_session_pdo_client_even_under_db_driver_native(string $dialect, string $expected): void
    {
        $context = new MigrationContext('/irrelevant', new Config([
            'DB_CONNECTION' => $dialect,
            'DB_DRIVER' => 'native',
            'DB_PASSWORD' => 'secret',
        ]));

        $link = self::linkOf($context->runner(new CommandArguments([], [])));

        self::assertInstanceOf($expected, $link);
        self::assertTrue(self::property($link, 'singleSession'));
    }

    /**
     * The link comes from kinetis/database-bridge's connection policy,
     * which is what reports it through Kinetis telemetry.
     */
    public function test_the_runner_link_is_built_by_the_bridge_connection_policy(): void
    {
        $context = new MigrationContext('/irrelevant', new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_PASSWORD' => 'secret',
        ]));

        $link = self::linkOf($context->runner(new CommandArguments([], [])));

        self::assertInstanceOf(
            TelemetrySqlInstrumentation::class,
            self::property(self::property($link, 'instrumentation'), 'instrumentation'),
        );
    }

    /** The named-connection form reads its own scoped keys, and gets the same client. */
    public function test_a_named_connection_gets_a_pdo_client_too(): void
    {
        $context = new MigrationContext('/irrelevant', new Config([
            'DB_REPORTS_CONNECTION' => 'pgsql',
            'DB_REPORTS_DRIVER' => 'native',
            'DB_REPORTS_PASSWORD' => 'secret',
        ]));

        $runner = $context->runner(new CommandArguments([], ['connection' => 'reports']));

        self::assertInstanceOf(PdoPgsqlClient::class, self::linkOf($runner));
    }

    public function test_the_default_connection_runs_when_nothing_names_one(): void
    {
        $context = new MigrationContext('/irrelevant', self::twoConnections([]));

        self::assertInstanceOf(PdoMysqlClient::class, self::linkOf($context->runner(new CommandArguments([], []))));
    }

    public function test_migrate_connection_name_selects_the_connection_without_a_flag(): void
    {
        $context = new MigrationContext('/irrelevant', self::twoConnections(['MIGRATE_CONNECTION_NAME' => 'reports']));

        self::assertInstanceOf(PdoPgsqlClient::class, self::linkOf($context->runner(new CommandArguments([], []))));
    }

    public function test_the_connection_flag_wins_over_migrate_connection_name(): void
    {
        $context = new MigrationContext('/irrelevant', self::twoConnections(['MIGRATE_CONNECTION_NAME' => 'reports']));

        $runner = $context->runner(new CommandArguments([], ['connection' => 'default']));

        self::assertInstanceOf(PdoMysqlClient::class, self::linkOf($runner));
    }

    /** @param array<string, string> $overrides */
    private static function twoConnections(array $overrides): Config
    {
        return new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_PASSWORD' => 'secret',
            'DB_REPORTS_CONNECTION' => 'pgsql',
            'DB_REPORTS_PASSWORD' => 'secret',
            ...$overrides,
        ]);
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
