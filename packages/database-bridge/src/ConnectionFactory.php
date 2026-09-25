<?php

declare(strict_types=1);

namespace Kinetis\DatabaseBridge;

use InvalidArgumentException;
use Kinetis\Config\Config;
use Kinetis\Instrumentation\Telemetry;
use Kinetis\Persistence\ConnectionDefinition;
use Kinetis\Persistence\ConnectionOptions;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Persistence\SqlConnectionFactory;

/**
 * Builds a kinetis/persistence client from Config's DB_* keys, reporting
 * through Kinetis telemetry.
 *
 * `DB_DRIVER` (`auto`, the default, `native` or `pdo`) selects the
 * driver as {@see SqlConnectionFactory} describes. Connection options
 * come from discrete keys — `DB_CHARSET`, `DB_COLLATION`, `DB_SSLMODE`,
 * `DB_SSL_CA`, `DB_SSL_CERT`, `DB_SSL_KEY`, `DB_CONNECT_TIMEOUT`,
 * `DB_APP_NAME`, `DB_COMPRESSION` — into {@see ConnectionOptions}.
 *
 * $connection selects a named connection via Config::scopedKey() —
 * 'default' reads the plain DB_* keys; any other name reads DB_{NAME}_*,
 * every key included.
 *
 * $driver overrides `DB_DRIVER` for one call. {@see singleSession()}
 * is the stricter form of the same thing, for a caller whose work lives
 * in the database session itself.
 *
 * $poolOptions['maxConnections'] (or `DB_MAX_CONNECTIONS`) caps the
 * async drivers' fan-out width. $poolOptions['warmConnections'] (or
 * `DB_WARM_CONNECTIONS`) opens that many connections at construction
 * instead of on first use — see each driver's warmUp() for why a
 * persistent worker should warm its mysqli pool at boot. An explicit
 * pool option wins over its key.
 *
 * Every client reports through {@see Telemetry::global()}.
 */
final class ConnectionFactory
{
    /**
     * @param array<string, mixed> $poolOptions
     * @param 'auto'|'native'|'pdo'|null $driver Overrides the DB_DRIVER
     *     key when given.
     */
    public static function fromConfig(
        Config $config,
        string $connection = 'default',
        array $poolOptions = [],
        ?string $driver = null,
    ): MysqlLink|PostgresLink {
        return SqlConnectionFactory::create(
            self::definition($config, $connection, $poolOptions, $driver),
            new TelemetrySqlInstrumentation(Telemetry::global()),
        );
    }

    /**
     * {@see SqlConnectionFactory::singleSession()} for a connection's
     * DB_* keys: PDO whatever DB_DRIVER says, and closed rather than
     * reconnected if it loses its session. The migrate:* commands run on
     * one.
     */
    public static function singleSession(Config $config, string $connection = 'default'): MysqlLink|PostgresLink
    {
        return SqlConnectionFactory::singleSession(
            self::definition($config, $connection, [], 'pdo'),
            new TelemetrySqlInstrumentation(Telemetry::global()),
        );
    }

    /**
     * Every key is read and validated here, before any driver is
     * constructed, so a bad value never depends on which driver happens
     * to be lazy about opening a real connection.
     *
     * @param array<string, mixed> $poolOptions
     */
    private static function definition(
        Config $config,
        string $connection,
        array $poolOptions,
        ?string $driver,
    ): ConnectionDefinition {
        // The dialect first: a connection whose block is missing entirely
        // is reported by the key that declares it.
        $dialectKey = Config::scopedKey('DB_CONNECTION', $connection);
        $dialect = $config->required($dialectKey);

        if ($dialect !== 'mysql' && $dialect !== 'pgsql') {
            throw new InvalidArgumentException("{$dialectKey} must be \"mysql\" or \"pgsql\".");
        }

        $host = $config->string(Config::scopedKey('DB_HOST', $connection), '127.0.0.1');
        $database = $config->string(Config::scopedKey('DB_NAME', $connection), 'app');
        $user = $config->string(Config::scopedKey('DB_USER', $connection), 'app');
        $password = $config->required(Config::scopedKey('DB_PASSWORD', $connection));

        $driver ??= $config->string(Config::scopedKey('DB_DRIVER', $connection), 'auto');

        if ($driver !== 'auto' && $driver !== 'native' && $driver !== 'pdo') {
            throw new InvalidArgumentException(
                'The database driver must be "auto", "native", or "pdo", got "' . $driver . '" — from the '
                . '$driver argument or ' . Config::scopedKey('DB_DRIVER', $connection) . '.',
            );
        }

        $portKey = Config::scopedKey('DB_PORT', $connection);
        $port = $config->int($portKey, $dialect === 'mysql' ? 3306 : 5432);

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException("{$portKey} must be a valid TCP port (1-65535), got {$port}.");
        }

        $options = self::options($config, $connection, $poolOptions);

        // An explicit code-level poolOption wins over the env key, same
        // as maxConnections in options().
        $warmConnectionsKey = Config::scopedKey('DB_WARM_CONNECTIONS', $connection);
        $warmConnections = self::intPoolOption($poolOptions, 'warmConnections')
            ?? $config->int($warmConnectionsKey, 0);

        if ($warmConnections < 0) {
            throw new InvalidArgumentException("{$warmConnectionsKey} must not be negative, got {$warmConnections}.");
        }

        return new ConnectionDefinition(
            dialect: $dialect,
            host: $host,
            database: $database,
            user: $user,
            password: $password,
            port: $port,
            driver: $driver,
            options: $options,
            warmConnections: $warmConnections,
        );
    }

    /**
     * A $poolOptions override is declared array<string, mixed> — real
     * consumer code, not a Config-parsed string — so an int-typed pool
     * setting given the wrong shape (a string, a float, an object) must
     * be a clear, owning-factory configuration error naming the actual
     * type given, not an incidental TypeError several calls deeper once
     * it reaches a real int-typed constructor parameter (ConnectionOptions'
     * own $maxConnections, most notably). Returns null when the key is
     * absent, so the caller's own Config-key fallback applies.
     *
     * @param array<string, mixed> $poolOptions
     */
    private static function intPoolOption(array $poolOptions, string $key): ?int
    {
        if (!\array_key_exists($key, $poolOptions)) {
            return null;
        }

        $value = $poolOptions[$key];

        if (!\is_int($value)) {
            throw new InvalidArgumentException(
                "\$poolOptions['{$key}'] must be an int, got " . \get_debug_type($value) . '.',
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $poolOptions
     */
    private static function options(Config $config, string $connection, array $poolOptions): ConnectionOptions
    {
        $compression = $config->get(Config::scopedKey('DB_COMPRESSION', $connection));
        $connectTimeout = $config->intOrNull(Config::scopedKey('DB_CONNECT_TIMEOUT', $connection));

        // An explicit code-level poolOption wins; the connection-scoped
        // env key covers deployments tuning pool width without editing
        // bootstrap code (see docs/performance-tuning.md for sizing).
        // The lower bound (>= 1) is ConnectionOptions' own job; this only
        // guards the type, so a non-int poolOption is this factory's own
        // clear error rather than an incidental TypeError from that
        // constructor.
        $maxConnections = self::intPoolOption($poolOptions, 'maxConnections')
            ?? $config->int(Config::scopedKey('DB_MAX_CONNECTIONS', $connection), 8);

        return new ConnectionOptions(
            charset: $config->get(Config::scopedKey('DB_CHARSET', $connection)),
            collation: $config->get(Config::scopedKey('DB_COLLATION', $connection)),
            sslMode: $config->get(Config::scopedKey('DB_SSLMODE', $connection)),
            sslCa: $config->get(Config::scopedKey('DB_SSL_CA', $connection)),
            connectTimeout: $connectTimeout,
            applicationName: $config->get(Config::scopedKey('DB_APP_NAME', $connection)),
            compression: $compression !== null ? \in_array(\strtolower((string) $compression), ['1', 'true', 'on', 'yes'], true) : null,
            maxConnections: $maxConnections,
            sslCert: $config->get(Config::scopedKey('DB_SSL_CERT', $connection)),
            sslKey: $config->get(Config::scopedKey('DB_SSL_KEY', $connection)),
        );
    }
}
