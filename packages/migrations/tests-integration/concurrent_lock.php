<?php

declare(strict_types=1);

/**
 * Proves MigrationRunner's cross-process advisory lock for real: two
 * genuinely separate OS processes (proc_open(), not two Fiber tasks in
 * one process) racing migrate() against the same migrations directory
 * and the same database, for both MySQL and Postgres.
 *
 * The fixture migration's up() sleeps before creating its table, widening
 * the race window, then INSERTs one row into a side table with a fixed
 * primary key — if the lock ever let both processes execute up()
 * concurrently, the second INSERT collides on that key and throws,
 * a concrete, checkable failure signature distinguishing "the lock
 * worked" from "it happened not to race this run."
 */

require __DIR__ . '/../vendor/autoload.php';

function check(string $label, bool $condition): void
{
    echo ($condition ? "OK   " : "FAIL ") . $label . "\n";

    if (!$condition) {
        exit(1);
    }
}

function writeFixtureMigration(string $dir): void
{
    if (!is_dir($dir)) {
        mkdir($dir, recursive: true);
    }

    file_put_contents($dir . '/20260101000000_create_widgets_table.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        use Kinetis\Persistence\Contract\MysqlLink;
        use Kinetis\Persistence\Contract\PostgresLink;
        use Kinetis\Migrations\Migration;

        return new class implements Migration
        {
            public function up(MysqlLink|PostgresLink $db): void
            {
                usleep(500_000);
                $db->execute('CREATE TABLE widgets (id INT PRIMARY KEY, name VARCHAR(50) NOT NULL)');
                // A fixed-key INSERT: a second concurrent up() colliding
                // here is the real, checkable proof the lock failed to
                // serialize the two racing processes.
                $db->execute('INSERT INTO widgets_lock_proof (id) VALUES (1)');
            }

            public function down(MysqlLink|PostgresLink $db): void
            {
                $db->execute('DROP TABLE widgets');
            }
        };
        PHP);
}

/** @param callable(): mixed $connect */
function runConcurrencyProof(string $backend, callable $connect, string $envPrefix): void
{
    echo "=== {$backend} concurrent lock ===\n";

    $link = $connect();
    $link->execute('DROP TABLE IF EXISTS widgets');
    $link->execute('DROP TABLE IF EXISTS widgets_lock_proof');
    $link->execute('DROP TABLE IF EXISTS kinetis_migrations');
    $link->execute('CREATE TABLE widgets_lock_proof (id INT PRIMARY KEY)');
    $link->close();

    $migrationsPath = sys_get_temp_dir() . '/kinetis-migrations-lock-proof-' . strtolower($backend);
    writeFixtureMigration($migrationsPath);

    $runnerScript = __DIR__ . '/concurrent_lock_worker.php';
    $outFiles = [
        sys_get_temp_dir() . '/kinetis-lock-proof-' . strtolower($backend) . '-1.json',
        sys_get_temp_dir() . '/kinetis-lock-proof-' . strtolower($backend) . '-2.json',
    ];

    $processes = [];

    foreach ($outFiles as $outFile) {
        @unlink($outFile);
        $processes[] = proc_open(
            ['php', $runnerScript, $envPrefix, $migrationsPath, $outFile],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
    }

    foreach ($processes as $process) {
        proc_close($process);
    }

    $results = array_map(
        static fn (string $file): array => json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR),
        $outFiles,
    );

    $bothSucceeded = $results[0]['ok'] && $results[1]['ok'];
    check("{$backend}: both racing processes completed without an unhandled error", $bothSucceeded);

    if (!$bothSucceeded) {
        foreach ($results as $i => $result) {
            echo "  process " . ($i + 1) . ": " . json_encode($result) . "\n";
        }
    }

    $totalApplied = count($results[0]['applied']) + count($results[1]['applied']);
    check("{$backend}: the migration was applied exactly once across both processes", $totalApplied === 1);

    $verify = $connect();
    $count = (int) ($verify->execute('SELECT COUNT(*) AS n FROM widgets_lock_proof')->fetchRow()['n'] ?? -1);
    $verify->close();
    check("{$backend}: exactly one row landed in the collision-proof table", $count === 1);

    foreach ($outFiles as $file) {
        @unlink($file);
    }

    echo "\n";
}

runConcurrencyProof(
    'MySQL',
    static fn () => new Kinetis\Persistence\Driver\MysqliAsyncClient(
        getenv('MYSQL_HOST') ?: '127.0.0.1',
        getenv('MYSQL_USER') ?: 'testuser',
        getenv('MYSQL_PASSWORD') ?: 'testpass',
        getenv('MYSQL_DATABASE') ?: 'testdb',
        (int) (getenv('MYSQL_PORT') ?: 3306),
    ),
    'MYSQL',
);

runConcurrencyProof(
    'Postgres',
    static fn () => new Kinetis\Persistence\Driver\PgsqlAsyncClient(
        getenv('POSTGRES_HOST') ?: '127.0.0.1',
        getenv('POSTGRES_USER') ?: 'testuser',
        getenv('POSTGRES_PASSWORD') ?: 'testpass',
        getenv('POSTGRES_DATABASE') ?: 'testdb',
        (int) (getenv('POSTGRES_PORT') ?: 5432),
    ),
    'POSTGRES',
);

echo "ALL CHECKS PASSED\n";
