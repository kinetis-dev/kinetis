<?php

declare(strict_types=1);

namespace Kinetis\QueueFailedJobs\Tests\Integration;

use Kinetis\Config\Config;
use Kinetis\Migrations\Migration;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use PHPUnit\Framework\TestCase;

/**
 * The environment gate and the DB_* keys for the CI integration servers:
 * MYSQL_HOST for the MySQL family, POSTGRES_HOST for Postgres. A test
 * whose server is not configured is skipped.
 */
final class Backend
{
    // Never instantiated — every method here is static.
    private function __construct() {}

    /**
     * The DB_* (or DB_{NAME}_*) keys pointing $connection at $dialect's
     * server, or a skip when that server is not configured.
     *
     * @param 'mysql'|'pgsql' $dialect
     * @return array<string, string>
     */
    public static function keys(string $dialect, string $connection = 'default'): array
    {
        $prefix = $dialect === 'mysql' ? 'MYSQL' : 'POSTGRES';
        $host = getenv("{$prefix}_HOST");

        if ($host === false) {
            TestCase::markTestSkipped("{$prefix}_HOST is not set — real-backend tests are environment-gated.");
        }

        return [
            Config::scopedKey('DB_CONNECTION', $connection) => $dialect,
            Config::scopedKey('DB_HOST', $connection) => $host,
            Config::scopedKey('DB_PORT', $connection) => getenv("{$prefix}_PORT") ?: ($dialect === 'mysql' ? '3306' : '5432'),
            Config::scopedKey('DB_NAME', $connection) => getenv("{$prefix}_DATABASE") ?: 'testdb',
            Config::scopedKey('DB_USER', $connection) => getenv("{$prefix}_USER") ?: 'testuser',
            Config::scopedKey('DB_PASSWORD', $connection) => getenv("{$prefix}_PASSWORD") ?: 'testpass',
        ];
    }

    /**
     * Recreates a table from a shipped migration stub, so the stubs
     * themselves are what the tests run against.
     */
    public static function migrate(MysqlLink|PostgresLink $link, string $stubDirectory, string $table): void
    {
        $dialect = $link instanceof MysqlLink ? 'mysql' : 'pgsql';
        $link->execute("DROP TABLE IF EXISTS {$table}");

        /** @var Migration $migration */
        $migration = require "{$stubDirectory}/create_{$table}_table.{$dialect}.php.stub";
        $migration->up($link);
    }

    public static function failedJobsStubs(): string
    {
        return \dirname(__DIR__, 2) . '/resources/migrations';
    }
}
