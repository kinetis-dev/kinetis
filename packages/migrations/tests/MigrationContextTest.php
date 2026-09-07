<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Tests;

use Kinetis\Config\Config;
use Kinetis\Console\CommandArguments;
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
    public function test_the_runner_gets_a_pdo_client_even_under_db_driver_native(string $dialect, string $expected): void
    {
        $context = new MigrationContext('/irrelevant', new Config([
            'DB_CONNECTION' => $dialect,
            'DB_DRIVER' => 'native',
            'DB_PASSWORD' => 'secret',
        ]));

        self::assertInstanceOf($expected, self::linkOf($context->runner(new CommandArguments([], []))));
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

    private static function linkOf(MigrationRunner $runner): object
    {
        $link = new ReflectionProperty(MigrationRunner::class, 'db')->getValue($runner);
        \assert(\is_object($link));

        return $link;
    }
}
