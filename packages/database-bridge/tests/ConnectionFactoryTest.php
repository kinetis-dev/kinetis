<?php

declare(strict_types=1);

namespace Kinetis\DatabaseBridge\Tests;

use InvalidArgumentException;
use Kinetis\Config\Config;
use Kinetis\Config\Exception\InvalidConfigValueException;
use Kinetis\Config\Exception\MissingConfigException;
use Kinetis\DatabaseBridge\ConnectionFactory;
use Kinetis\DatabaseBridge\TelemetrySqlInstrumentation;
use Kinetis\Instrumentation\Telemetry;
use Kinetis\Persistence\ConnectionOptions;
use Kinetis\Persistence\Driver\ContainedSqlInstrumentation;
use Kinetis\Persistence\Driver\MysqliAsyncClient;
use Kinetis\Persistence\Driver\PdoMysqlClient;
use Kinetis\Persistence\Driver\PdoPgsqlClient;
use Kinetis\Persistence\Driver\PgsqlAsyncClient;
use Kinetis\Persistence\Exception\ConnectionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class ConnectionFactoryTest extends TestCase
{
    public function test_auto_driver_selects_pdo_outside_a_persistent_runtime(): void
    {
        // The test process is not a FrankenPHP worker, so 'auto' must fall back to PDO.
        $config = new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_PASSWORD' => 'secret',
        ]);

        self::assertInstanceOf(PdoMysqlClient::class, ConnectionFactory::fromConfig($config));
    }

    public function test_auto_driver_selects_native_under_road_runner(): void
    {
        // RR_MODE=http is the same signal RuntimeDetector::detect() uses
        // to pick RoadRunnerAdapter — 'auto' must treat it as a
        // persistent runtime exactly like a real FrankenPHP worker.
        $original = getenv('RR_MODE');
        putenv('RR_MODE=http');

        try {
            $config = new Config([
                'DB_CONNECTION' => 'mysql',
                'DB_PASSWORD' => 'secret',
            ]);

            self::assertInstanceOf(MysqliAsyncClient::class, ConnectionFactory::fromConfig($config));
        } finally {
            putenv($original === false ? 'RR_MODE' : "RR_MODE={$original}");
        }
    }

    public function test_native_driver_builds_the_mysqli_async_client(): void
    {
        $config = new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_DRIVER' => 'native',
            'DB_PASSWORD' => 'secret',
        ]);

        self::assertInstanceOf(MysqliAsyncClient::class, ConnectionFactory::fromConfig($config));
    }

    public function test_native_driver_builds_the_pgsql_async_client(): void
    {
        $config = new Config([
            'DB_CONNECTION' => 'pgsql',
            'DB_DRIVER' => 'native',
            'DB_PASSWORD' => 'secret',
        ]);

        self::assertInstanceOf(PgsqlAsyncClient::class, ConnectionFactory::fromConfig($config));
    }

    public function test_pdo_driver_builds_the_pdo_pgsql_client(): void
    {
        $config = new Config([
            'DB_CONNECTION' => 'pgsql',
            'DB_DRIVER' => 'pdo',
            'DB_PASSWORD' => 'secret',
        ]);

        self::assertInstanceOf(PdoPgsqlClient::class, ConnectionFactory::fromConfig($config));
    }

    public function test_driver_selection_is_scoped_per_named_connection(): void
    {
        $config = new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_DRIVER' => 'pdo',
            'DB_PASSWORD' => 'secret',
            'DB_DB2_CONNECTION' => 'mysql',
            'DB_DB2_DRIVER' => 'native',
            'DB_DB2_PASSWORD' => 'secret',
        ]);

        self::assertInstanceOf(PdoMysqlClient::class, ConnectionFactory::fromConfig($config));
        self::assertInstanceOf(MysqliAsyncClient::class, ConnectionFactory::fromConfig($config, 'db2'));
    }

    public function test_every_key_of_a_named_connection_is_scoped(): void
    {
        $client = ConnectionFactory::fromConfig(new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_PASSWORD' => 'default-secret',
            'DB_REPORTS_CONNECTION' => 'pgsql',
            'DB_REPORTS_DRIVER' => 'native',
            'DB_REPORTS_HOST' => 'reports.internal',
            'DB_REPORTS_NAME' => 'reports',
            'DB_REPORTS_USER' => 'reporter',
            'DB_REPORTS_PASSWORD' => 'reports-secret',
            'DB_REPORTS_PORT' => '15432',
        ]), 'reports');

        self::assertInstanceOf(PgsqlAsyncClient::class, $client);
        self::assertSame('reports.internal', self::property($client, 'host'));
        self::assertSame('reports', self::property($client, 'database'));
        self::assertSame('reporter', self::property($client, 'user'));
        self::assertSame('reports-secret', self::property($client, 'password'));
        self::assertSame(15432, self::property($client, 'port'));
    }

    public function test_host_database_and_user_default_when_unset(): void
    {
        $client = ConnectionFactory::fromConfig(new Config([
            'DB_CONNECTION' => 'mysql', 'DB_DRIVER' => 'native', 'DB_PASSWORD' => 's',
        ]));

        self::assertSame('127.0.0.1', self::property($client, 'host'));
        self::assertSame('app', self::property($client, 'database'));
        self::assertSame('app', self::property($client, 'user'));
    }

    public function test_throws_a_clear_error_when_the_dialect_is_missing_for_the_default_connection(): void
    {
        $config = new Config(['DB_PASSWORD' => 'secret']);

        $this->expectException(MissingConfigException::class);
        $this->expectExceptionMessage('DB_CONNECTION');
        ConnectionFactory::fromConfig($config);
    }

    public function test_throws_a_clear_error_naming_the_named_connections_own_key_when_the_dialect_is_missing(): void
    {
        $config = new Config(['DB_DB2_PASSWORD' => 'secret']);

        $this->expectException(MissingConfigException::class);
        $this->expectExceptionMessage('DB_DB2_CONNECTION');
        ConnectionFactory::fromConfig($config, 'db2');
    }

    public function test_a_wholly_missing_named_block_names_its_connection_key(): void
    {
        try {
            ConnectionFactory::singleSession(new Config(['DB_CONNECTION' => 'mysql', 'DB_PASSWORD' => 'secret']), 'reporting');
            self::fail('A missing named block must throw.');
        } catch (MissingConfigException $e) {
            self::assertSame('Missing required config value "DB_REPORTING_CONNECTION".', $e->getMessage());
        }
    }

    public function test_a_missing_password_throws_a_clear_error(): void
    {
        $this->expectException(MissingConfigException::class);
        $this->expectExceptionMessage('DB_PASSWORD');
        ConnectionFactory::fromConfig(new Config(['DB_CONNECTION' => 'mysql']));
    }

    public function test_throws_when_the_dialect_is_neither_mysql_nor_pgsql(): void
    {
        $config = new Config(['DB_CONNECTION' => 'sqlite', 'DB_PASSWORD' => 'secret']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('DB_CONNECTION must be "mysql" or "pgsql".');
        ConnectionFactory::fromConfig($config);
    }

    public function test_throws_when_the_driver_is_unknown(): void
    {
        $config = new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_DRIVER' => 'odbc',
            'DB_PASSWORD' => 'secret',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The database driver must be "auto", "native", or "pdo", got "odbc" — from the $driver argument or DB_DRIVER.');
        ConnectionFactory::fromConfig($config);
    }

    /**
     * The $driver argument is for a caller that knows which driver its
     * own work needs, whatever the deployment's DB_DRIVER says.
     */
    public function test_an_explicit_driver_argument_overrides_the_db_driver_key(): void
    {
        $mysql = new Config(['DB_CONNECTION' => 'mysql', 'DB_DRIVER' => 'native', 'DB_PASSWORD' => 's']);
        $postgres = new Config(['DB_CONNECTION' => 'pgsql', 'DB_DRIVER' => 'native', 'DB_PASSWORD' => 's']);

        self::assertInstanceOf(PdoMysqlClient::class, ConnectionFactory::fromConfig($mysql, driver: 'pdo'));
        self::assertInstanceOf(PdoPgsqlClient::class, ConnectionFactory::fromConfig($postgres, driver: 'pdo'));

        // And without it, the key still decides.
        self::assertInstanceOf(MysqliAsyncClient::class, ConnectionFactory::fromConfig($mysql));
    }

    /**
     * singleSession() is that override plus a session policy: PDO on
     * either dialect, and closed rather than reconnected if it loses the
     * session — which is what the migrate:* commands' session-scoped
     * advisory lock needs. A DB_DRIVER value is not even read.
     */
    public function test_single_session_builds_a_pinned_pdo_client_whatever_db_driver_says(): void
    {
        $mysql = new Config(['DB_CONNECTION' => 'mysql', 'DB_DRIVER' => 'odbc', 'DB_PASSWORD' => 's']);
        $postgres = new Config(['DB_CONNECTION' => 'pgsql', 'DB_DRIVER' => 'native', 'DB_PASSWORD' => 's']);

        $mysqlClient = ConnectionFactory::singleSession($mysql);
        $postgresClient = ConnectionFactory::singleSession($postgres);

        self::assertInstanceOf(PdoMysqlClient::class, $mysqlClient);
        self::assertInstanceOf(PdoPgsqlClient::class, $postgresClient);
        self::assertTrue(self::property($mysqlClient, 'singleSession'));
        self::assertTrue(self::property($postgresClient, 'singleSession'));
    }

    public function test_single_session_reads_a_named_connection(): void
    {
        $client = ConnectionFactory::singleSession(new Config([
            'DB_REPORTS_CONNECTION' => 'pgsql',
            'DB_REPORTS_PASSWORD' => 's',
        ]), 'reports');

        self::assertInstanceOf(PdoPgsqlClient::class, $client);
    }

    public function test_an_unknown_driver_argument_is_rejected_like_the_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The database driver must be "auto", "native", or "pdo", got "odbc"');
        ConnectionFactory::fromConfig(
            new Config(['DB_CONNECTION' => 'mysql', 'DB_PASSWORD' => 's']),
            driver: 'odbc',
        );
    }

    public function test_an_option_the_selected_driver_cannot_honor_fails_loudly(): void
    {
        // applicationName is a Postgres concept; the mysqli driver must
        // reject it at construction, never silently ignore it.
        $config = new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_DRIVER' => 'native',
            'DB_PASSWORD' => 'secret',
            'DB_APP_NAME' => 'myapp',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('applicationName');
        ConnectionFactory::fromConfig($config);
    }

    public function test_db_port_wins_and_dialect_defaults_apply_when_unset(): void
    {
        $withPort = ConnectionFactory::fromConfig(new Config([
            'DB_CONNECTION' => 'mysql', 'DB_DRIVER' => 'native', 'DB_PASSWORD' => 's', 'DB_PORT' => '13306',
        ]));
        self::assertSame(13306, self::property($withPort, 'port'));

        $mysqlDefault = ConnectionFactory::fromConfig(new Config([
            'DB_CONNECTION' => 'mysql', 'DB_DRIVER' => 'native', 'DB_PASSWORD' => 's',
        ]));
        self::assertSame(3306, self::property($mysqlDefault, 'port'));

        $pgDefault = ConnectionFactory::fromConfig(new Config([
            'DB_CONNECTION' => 'pgsql', 'DB_DRIVER' => 'native', 'DB_PASSWORD' => 's',
        ]));
        self::assertSame(5432, self::property($pgDefault, 'port'));
    }

    public function test_discrete_option_keys_reach_the_driver(): void
    {
        $client = ConnectionFactory::fromConfig(new Config([
            'DB_CONNECTION' => 'pgsql',
            'DB_DRIVER' => 'native',
            'DB_PASSWORD' => 's',
            'DB_CHARSET' => 'UTF8',
            'DB_SSLMODE' => 'require',
            'DB_CONNECT_TIMEOUT' => '7',
            'DB_APP_NAME' => 'myapp',
        ]));

        $options = self::property($client, 'options');
        self::assertInstanceOf(ConnectionOptions::class, $options);
        self::assertSame('UTF8', $options->charset);
        self::assertSame('require', $options->sslMode);
        self::assertSame(7, $options->connectTimeout);
        self::assertSame('myapp', $options->applicationName);
    }

    public function test_compression_truthy_spellings_parse_to_true(): void
    {
        foreach (['1', 'true', 'on', 'yes'] as $spelling) {
            $client = ConnectionFactory::fromConfig(new Config([
                'DB_CONNECTION' => 'mysql',
                'DB_DRIVER' => 'native',
                'DB_PASSWORD' => 's',
                'DB_COMPRESSION' => $spelling,
            ]));

            $options = self::property($client, 'options');
            self::assertInstanceOf(ConnectionOptions::class, $options);
            self::assertTrue($options->compression, "spelling: {$spelling}");
        }
    }

    public function test_compression_truthy_spellings_are_matched_case_insensitively(): void
    {
        foreach (['TRUE', 'ON', 'YES', 'True', 'On'] as $spelling) {
            $client = ConnectionFactory::fromConfig(new Config([
                'DB_CONNECTION' => 'mysql',
                'DB_DRIVER' => 'native',
                'DB_PASSWORD' => 's',
                'DB_COMPRESSION' => $spelling,
            ]));

            $options = self::property($client, 'options');
            self::assertInstanceOf(ConnectionOptions::class, $options);
            self::assertTrue($options->compression, "spelling: {$spelling}");
        }
    }

    public function test_max_connections_pool_option_reaches_the_driver(): void
    {
        $client = ConnectionFactory::fromConfig(new Config([
            'DB_CONNECTION' => 'mysql', 'DB_DRIVER' => 'native', 'DB_PASSWORD' => 's',
        ]), poolOptions: ['maxConnections' => 3]);

        self::assertSame(3, self::maxConnectionsOf($client));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function nonIntPoolOptionValues(): array
    {
        return [
            'a string' => ['garbage'],
            'a float' => [1.9],
            'an object' => [new \stdClass()],
            'a bool' => [true],
        ];
    }

    #[DataProvider('nonIntPoolOptionValues')]
    public function test_a_non_int_max_connections_pool_option_is_a_clear_configuration_error(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("\$poolOptions['maxConnections'] must be an int, got");

        ConnectionFactory::fromConfig(new Config([
            'DB_CONNECTION' => 'mysql', 'DB_DRIVER' => 'native', 'DB_PASSWORD' => 's',
        ]), poolOptions: ['maxConnections' => $value]);
    }

    #[DataProvider('nonIntPoolOptionValues')]
    public function test_a_non_int_warm_connections_pool_option_is_a_clear_configuration_error(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("\$poolOptions['warmConnections'] must be an int, got");

        ConnectionFactory::fromConfig(new Config([
            'DB_CONNECTION' => 'mysql', 'DB_DRIVER' => 'native', 'DB_PASSWORD' => 's',
        ]), poolOptions: ['warmConnections' => $value]);
    }

    public function test_db_max_connections_env_key_sizes_the_pool(): void
    {
        $config = new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_DRIVER' => 'native',
            'DB_PASSWORD' => 'secret',
            'DB_MAX_CONNECTIONS' => '12',
        ]);

        self::assertSame(12, self::maxConnectionsOf(ConnectionFactory::fromConfig($config)));
    }

    public function test_an_explicit_pool_option_wins_over_the_env_key(): void
    {
        $config = new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_DRIVER' => 'native',
            'DB_PASSWORD' => 'secret',
            'DB_MAX_CONNECTIONS' => '12',
        ]);

        $client = ConnectionFactory::fromConfig($config, poolOptions: ['maxConnections' => 3]);

        self::assertSame(3, self::maxConnectionsOf($client));
    }

    public function test_pool_width_defaults_when_neither_env_nor_pool_option_is_set(): void
    {
        $config = new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_DRIVER' => 'native',
            'DB_PASSWORD' => 'secret',
        ]);

        self::assertSame(8, self::maxConnectionsOf(ConnectionFactory::fromConfig($config)));
    }

    public function test_db_max_connections_is_scoped_per_named_connection(): void
    {
        $config = new Config([
            'DB_ANALYTICS_CONNECTION' => 'mysql',
            'DB_ANALYTICS_DRIVER' => 'native',
            'DB_ANALYTICS_PASSWORD' => 'secret',
            'DB_ANALYTICS_MAX_CONNECTIONS' => '5',
        ]);

        self::assertSame(5, self::maxConnectionsOf(ConnectionFactory::fromConfig($config, 'analytics')));
    }

    public function test_a_non_numeric_db_max_connections_throws(): void
    {
        $this->expectException(InvalidConfigValueException::class);

        ConnectionFactory::fromConfig(new Config([
            'DB_CONNECTION' => 'mysql', 'DB_DRIVER' => 'native', 'DB_PASSWORD' => 's',
            'DB_MAX_CONNECTIONS' => 'many',
        ]));
    }

    public function test_a_non_numeric_db_port_throws(): void
    {
        $this->expectException(InvalidConfigValueException::class);

        ConnectionFactory::fromConfig(new Config([
            'DB_CONNECTION' => 'mysql', 'DB_DRIVER' => 'native', 'DB_PASSWORD' => 's',
            'DB_PORT' => 'not-a-port',
        ]));
    }

    public function test_a_non_numeric_db_warm_connections_throws(): void
    {
        $this->expectException(InvalidConfigValueException::class);

        ConnectionFactory::fromConfig(new Config([
            'DB_CONNECTION' => 'mysql', 'DB_DRIVER' => 'native', 'DB_PASSWORD' => 's',
            'DB_WARM_CONNECTIONS' => 'lots',
        ]));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function outOfRangePortCases(): array
    {
        return [
            'zero' => ['0'],
            'negative' => ['-1'],
            'beyond 65535' => ['65536'],
        ];
    }

    #[DataProvider('outOfRangePortCases')]
    public function test_a_db_port_outside_the_valid_tcp_range_throws(string $port): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("DB_PORT must be a valid TCP port (1-65535), got {$port}.");

        ConnectionFactory::fromConfig(new Config([
            'DB_CONNECTION' => 'mysql', 'DB_DRIVER' => 'native', 'DB_PASSWORD' => 's',
            'DB_PORT' => $port,
        ]));
    }

    public function test_a_named_connections_port_error_names_its_own_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('DB_REPORTS_PORT must be a valid TCP port (1-65535), got 0.');

        ConnectionFactory::fromConfig(new Config([
            'DB_REPORTS_CONNECTION' => 'mysql', 'DB_REPORTS_PASSWORD' => 's', 'DB_REPORTS_PORT' => '0',
        ]), 'reports');
    }

    public function test_a_negative_db_warm_connections_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('DB_WARM_CONNECTIONS must not be negative, got -1.');

        ConnectionFactory::fromConfig(new Config([
            'DB_CONNECTION' => 'mysql', 'DB_DRIVER' => 'native', 'DB_PASSWORD' => 's',
            'DB_WARM_CONNECTIONS' => '-1',
        ]));
    }

    public function test_a_non_numeric_db_connect_timeout_throws(): void
    {
        $this->expectException(InvalidConfigValueException::class);

        ConnectionFactory::fromConfig(new Config([
            'DB_CONNECTION' => 'mysql', 'DB_DRIVER' => 'native', 'DB_PASSWORD' => 's',
            'DB_CONNECT_TIMEOUT' => 'slow',
        ]));
    }

    #[RequiresPhpExtension('mysqli')]
    public function test_warm_connections_pool_option_connects_at_construction(): void
    {
        // Port 1 refuses immediately: reaching the network at all is
        // what proves warming fires inside fromConfig() rather than
        // deferring to the first query.
        $config = new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_DRIVER' => 'native',
            'DB_PASSWORD' => 'secret',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '1',
        ]);

        $this->expectException(ConnectionException::class);

        ConnectionFactory::fromConfig($config, poolOptions: ['warmConnections' => 1]);
    }

    #[RequiresPhpExtension('mysqli')]
    public function test_db_warm_connections_env_key_triggers_warming(): void
    {
        $config = new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_DRIVER' => 'native',
            'DB_PASSWORD' => 'secret',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '1',
            'DB_WARM_CONNECTIONS' => '1',
        ]);

        $this->expectException(ConnectionException::class);

        ConnectionFactory::fromConfig($config);
    }

    public function test_no_connection_is_opened_without_a_warming_request(): void
    {
        // Same unreachable endpoint as the warming tests: constructing
        // without warmConnections must not touch the network.
        $config = new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_DRIVER' => 'native',
            'DB_PASSWORD' => 'secret',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '1',
        ]);

        self::assertInstanceOf(MysqliAsyncClient::class, ConnectionFactory::fromConfig($config));
    }

    public function test_an_explicit_warm_pool_option_wins_over_the_warm_env_key(): void
    {
        // The env key asks for warming against an unreachable server;
        // the explicit zero must override it, so construction succeeds.
        $config = new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_DRIVER' => 'native',
            'DB_PASSWORD' => 'secret',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '1',
            'DB_WARM_CONNECTIONS' => '1',
        ]);

        $client = ConnectionFactory::fromConfig($config, poolOptions: ['warmConnections' => 0]);

        self::assertInstanceOf(MysqliAsyncClient::class, $client);
    }

    #[RequiresPhpExtension('pdo_mysql')]
    public function test_a_single_session_client_honors_db_warm_connections(): void
    {
        $this->expectException(ConnectionException::class);

        ConnectionFactory::singleSession(new Config([
            'DB_CONNECTION' => 'mysql',
            'DB_PASSWORD' => 'secret',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '1',
            'DB_WARM_CONNECTIONS' => '1',
        ]));
    }

    public function test_db_ssl_ca_reaches_the_driver(): void
    {
        $direct = ConnectionFactory::fromConfig(new Config([
            'DB_CONNECTION' => 'pgsql', 'DB_DRIVER' => 'native', 'DB_PASSWORD' => 's',
            'DB_SSLMODE' => 'verify-full', 'DB_SSL_CA' => '/certs/ca.pem',
        ]));

        $options = self::property($direct, 'options');
        self::assertInstanceOf(ConnectionOptions::class, $options);
        self::assertSame('/certs/ca.pem', $options->sslCa);
    }

    public function test_mysql_drivers_construct_with_a_verifying_tls_profile(): void
    {
        $config = new Config([
            'DB_CONNECTION' => 'mysql', 'DB_DRIVER' => 'native', 'DB_PASSWORD' => 's',
            'DB_SSLMODE' => 'verify-ca', 'DB_SSL_CA' => '/certs/ca.pem',
        ]);

        self::assertInstanceOf(MysqliAsyncClient::class, ConnectionFactory::fromConfig($config));
    }

    public function test_mysql_drivers_reject_a_libpq_only_ssl_mode(): void
    {
        $config = new Config([
            'DB_CONNECTION' => 'mysql', 'DB_DRIVER' => 'native', 'DB_PASSWORD' => 's',
            'DB_SSLMODE' => 'prefer',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no opportunistic TLS');
        ConnectionFactory::fromConfig($config);
    }

    /**
     * Clients report through the process-wide holder, not a backend
     * captured at construction, so kinetis/telemetry swapping its backend
     * in after package bootstraps ran still reaches them.
     */
    public function test_clients_report_through_the_process_wide_telemetry_holder(): void
    {
        $config = new Config(['DB_CONNECTION' => 'mysql', 'DB_DRIVER' => 'native', 'DB_PASSWORD' => 's']);

        foreach ([ConnectionFactory::fromConfig($config), ConnectionFactory::singleSession($config)] as $client) {
            $contained = self::property($client, 'instrumentation');
            self::assertInstanceOf(ContainedSqlInstrumentation::class, $contained);
            $instrumentation = self::property($contained, 'instrumentation');
            self::assertInstanceOf(TelemetrySqlInstrumentation::class, $instrumentation);
            self::assertSame(Telemetry::global(), self::property($instrumentation, 'telemetry'));
        }
    }

    private static function property(object $object, string $name): mixed
    {
        return new ReflectionProperty($object, $name)->getValue($object);
    }

    private static function maxConnectionsOf(object $client): int
    {
        $options = self::property($client, 'options');
        self::assertInstanceOf(ConnectionOptions::class, $options);

        return $options->maxConnections;
    }
}
