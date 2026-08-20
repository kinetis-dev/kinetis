<?php

declare(strict_types=1);

/**
 * One racing process for concurrent_lock.php — connects, runs migrate()
 * once, and writes the outcome to $argv[3] as JSON. A genuinely separate
 * PHP CLI process (spawned via proc_open in the parent script), not a
 * Fiber or thread within one process.
 */

require __DIR__ . '/../vendor/autoload.php';

use Kinetis\Migrations\MigrationRunner;
use Kinetis\Migrations\SqlMigrationRepository;
use Kinetis\Persistence\Driver\MysqliAsyncClient;
use Kinetis\Persistence\Driver\PgsqlAsyncClient;

[, $backend, $migrationsPath, $outFile] = $argv;

$link = $backend === 'MYSQL'
    ? new MysqliAsyncClient(
        getenv('MYSQL_HOST') ?: '127.0.0.1',
        getenv('MYSQL_USER') ?: 'testuser',
        getenv('MYSQL_PASSWORD') ?: 'testpass',
        getenv('MYSQL_DATABASE') ?: 'testdb',
        (int) (getenv('MYSQL_PORT') ?: 3306),
    )
    : new PgsqlAsyncClient(
        getenv('POSTGRES_HOST') ?: '127.0.0.1',
        getenv('POSTGRES_USER') ?: 'testuser',
        getenv('POSTGRES_PASSWORD') ?: 'testpass',
        getenv('POSTGRES_DATABASE') ?: 'testdb',
        (int) (getenv('POSTGRES_PORT') ?: 5432),
    );

$runner = new MigrationRunner($link, new SqlMigrationRepository($link), $migrationsPath);

try {
    $applied = $runner->migrate();
    file_put_contents($outFile, json_encode(['ok' => true, 'applied' => $applied], JSON_THROW_ON_ERROR));
} catch (Throwable $e) {
    file_put_contents($outFile, json_encode(
        ['ok' => false, 'applied' => [], 'error' => $e->getMessage()],
        JSON_THROW_ON_ERROR,
    ));
}
