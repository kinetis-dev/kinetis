<?php

declare(strict_types=1);

/**
 * Real-backend regression coverage for MigrationRunner/SqlMigrationRepository
 * — migrate()/status()/rollback() against a real fixture migration file,
 * for both MySQL and Postgres.
 */

require __DIR__ . '/../vendor/autoload.php';

use Kinetis\Persistence\Driver\MysqliAsyncClient;
use Kinetis\Persistence\Driver\PgsqlAsyncClient;
use Kinetis\Migrations\MigrationRunner;
use Kinetis\Migrations\SqlMigrationRepository;

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
                $db->execute('CREATE TABLE widgets (id INT PRIMARY KEY, name VARCHAR(50) NOT NULL)');
            }

            public function down(MysqlLink|PostgresLink $db): void
            {
                $db->execute('DROP TABLE widgets');
            }
        };
        PHP);
}

function run(string $backend, $link): void
{
    echo "=== {$backend} ===\n";

    $link->execute('DROP TABLE IF EXISTS widgets');
    $link->execute('DROP TABLE IF EXISTS kinetis_migrations');

    $migrationsPath = sys_get_temp_dir() . '/kinetis-migrations-integration-' . strtolower($backend);
    writeFixtureMigration($migrationsPath);

    $runner = new MigrationRunner($link, new SqlMigrationRepository($link), $migrationsPath);

    check("{$backend}: one migration is pending before running", count($runner->pending()) === 1);

    $applied = $runner->migrate();
    check("{$backend}: migrate() runs the pending migration", $applied === ['20260101000000_create_widgets_table']);
    check("{$backend}: the table actually exists now", count($link->execute('SELECT * FROM widgets')->fetchRow() ?? []) >= 0);

    $status = $runner->status();
    check("{$backend}: status() reports it as applied", $status[0]['applied'] === true);

    check("{$backend}: a second migrate() is a no-op", $runner->migrate() === []);

    $rolledBack = $runner->rollback();
    check("{$backend}: rollback() undoes the most recent migration", $rolledBack === '20260101000000_create_widgets_table');

    $statusAfterRollback = $runner->status();
    check("{$backend}: status() reports it as not applied after rollback", $statusAfterRollback[0]['applied'] === false);

    $rolledBackAgain = $runner->rollback();
    check("{$backend}: a second rollback() finds nothing to undo", $rolledBackAgain === null);

    echo "\n";
}

$mysql = new MysqliAsyncClient(
    getenv('MYSQL_HOST') ?: '127.0.0.1',
    getenv('MYSQL_USER') ?: 'testuser',
    getenv('MYSQL_PASSWORD') ?: 'testpass',
    getenv('MYSQL_DATABASE') ?: 'testdb',
    (int) (getenv('MYSQL_PORT') ?: 3306),
);
$postgres = new PgsqlAsyncClient(
    getenv('POSTGRES_HOST') ?: '127.0.0.1',
    getenv('POSTGRES_USER') ?: 'testuser',
    getenv('POSTGRES_PASSWORD') ?: 'testpass',
    getenv('POSTGRES_DATABASE') ?: 'testdb',
    (int) (getenv('POSTGRES_PORT') ?: 5432),
);

run('MySQL', $mysql);
run('Postgres', $postgres);

echo "ALL CHECKS PASSED\n";
