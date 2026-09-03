<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Tests;

use Kinetis\Config\Config;
use Kinetis\Migrations\Console\MigrationContext;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Persistence\ConnectionOptions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * connection() is private — there is no supported reason for an
 * application caller to bypass runner() and reach it directly — so
 * this file reaches it via reflection, the same established pattern
 * already used elsewhere for a class's own pure decision logic (see
 * Kinetis\Storage\AmpFileAdapterTest's resolveCopyVisibility()/
 * populateTempStream() tests). It never touches a real database:
 * every driver SqlConnectionFactory::fromConfig() can construct stores
 * its ConnectionOptions and validates them, but connects lazily on
 * first use, confirmed directly by Kinetis\Persistence's own design
 * (see SqlConnectionFactory's own "Warming connects right here"
 * comment: a plain construction with no DB_WARM_CONNECTIONS never
 * opens a socket).
 */
final class MigrationContextTest extends TestCase
{
    private function callConnection(MigrationContext $context, string $connectionName): MysqlLink|PostgresLink
    {
        /** @var MysqlLink|PostgresLink */
        return new ReflectionMethod($context, 'connection')->invoke($context, $connectionName);
    }

    /**
     * The migrate:* commands are strictly serial and gain nothing from
     * a pooled native connection — MigrationRunner's own advisory-lock
     * acquire/release calls need one session-stable connection, which
     * only a maxConnections: 1 pool guarantees. Proven directly here by
     * reflecting into the constructed client's own stored
     * ConnectionOptions, confirming a much larger configured
     * DB_MAX_CONNECTIONS is overridden, not merely defaulted around.
     */
    public function test_connection_forces_a_single_native_mysql_connection_regardless_of_configured_pool_width(): void
    {
        $config = new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_DRIVER' => 'native',
            'DB_PASSWORD' => 'secret',
            'DB_MAX_CONNECTIONS' => '50',
        ]);

        $client = $this->callConnection(new MigrationContext('/irrelevant', $config), 'default');

        $options = new ReflectionProperty($client, 'options')->getValue($client);

        self::assertInstanceOf(ConnectionOptions::class, $options);
        self::assertSame(1, $options->maxConnections);
    }

    public function test_connection_forces_a_single_native_postgres_connection_regardless_of_configured_pool_width(): void
    {
        $config = new Config([
            'DB_CONNECTION' => 'pgsql',
            'DB_DRIVER' => 'native',
            'DB_PASSWORD' => 'secret',
            'DB_MAX_CONNECTIONS' => '50',
        ]);

        $client = $this->callConnection(new MigrationContext('/irrelevant', $config), 'default');

        $options = new ReflectionProperty($client, 'options')->getValue($client);

        self::assertSame(1, $options->maxConnections);
    }

    /**
     * The named-connection form of the same guarantee — a scoped
     * DB_REPORTS_MAX_CONNECTIONS must be overridden identically to the
     * default-connection case above.
     */
    public function test_connection_forces_a_single_connection_for_a_named_connection_too(): void
    {
        $config = new Config([
            'DB_REPORTS_CONNECTION' => 'mysql',
            'DB_REPORTS_DRIVER' => 'native',
            'DB_REPORTS_PASSWORD' => 'secret',
            'DB_REPORTS_MAX_CONNECTIONS' => '50',
        ]);

        $client = $this->callConnection(new MigrationContext('/irrelevant', $config), 'reports');

        $options = new ReflectionProperty($client, 'options')->getValue($client);

        self::assertSame(1, $options->maxConnections);
    }
}
