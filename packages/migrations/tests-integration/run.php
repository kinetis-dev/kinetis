<?php

declare(strict_types=1);

/**
 * Real-backend regression coverage for MigrationRunner/SqlMigrationRepository
 * — migrate()/status()/rollback() against a real fixture migration file,
 * for both MySQL and Postgres.
 */

require __DIR__ . '/../vendor/autoload.php';

use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
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

        use Amp\Mysql\MysqlLink;
        use Amp\Postgres\PostgresLink;
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

$mysql = new MysqlConnectionPool(new MysqlConfig(
    host: getenv('MYSQL_HOST') ?: '127.0.0.1',
    user: getenv('MYSQL_USER') ?: 'testuser',
    password: getenv('MYSQL_PASSWORD') ?: 'testpass',
    database: getenv('MYSQL_DATABASE') ?: 'testdb',
));
$postgres = new PostgresConnectionPool(new PostgresConfig(
    host: getenv('POSTGRES_HOST') ?: '127.0.0.1',
    user: getenv('POSTGRES_USER') ?: 'testuser',
    password: getenv('POSTGRES_PASSWORD') ?: 'testpass',
    database: getenv('POSTGRES_DATABASE') ?: 'testdb',
));

run('MySQL', $mysql);
run('Postgres', $postgres);

echo "ALL CHECKS PASSED\n";
