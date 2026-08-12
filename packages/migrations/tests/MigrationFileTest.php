<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Tests;

use Kinetis\Migrations\MigrationFile;
use Kinetis\Migrations\Tests\Fixtures\FakeMysqlLink;
use PHPUnit\Framework\TestCase;

final class MigrationFileTest extends TestCase
{
    private string $migrationsPath;

    protected function setUp(): void
    {
        $this->migrationsPath = sys_get_temp_dir() . '/kinetis-migrations-file-test-' . bin2hex(random_bytes(8));
        mkdir($this->migrationsPath);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->migrationsPath . '/*.php') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->migrationsPath);
    }

    public function test_discover_returns_an_empty_list_when_the_directory_does_not_exist(): void
    {
        self::assertSame([], MigrationFile::discover($this->migrationsPath . '/does-not-exist'));
    }

    public function test_discover_sorts_by_filename_regardless_of_creation_order(): void
    {
        file_put_contents($this->migrationsPath . '/20260201000000_second.php', '<?php return null;');
        file_put_contents($this->migrationsPath . '/20260101000000_first.php', '<?php return null;');

        $files = MigrationFile::discover($this->migrationsPath);

        self::assertSame(
            ['20260101000000_first', '20260201000000_second'],
            array_map(static fn (MigrationFile $file): string => $file->name, $files),
        );
    }

    public function test_load_returns_a_migration_whose_up_and_down_receive_the_db_argument(): void
    {
        $path = $this->migrationsPath . '/20260101000000_records_db.php';

        file_put_contents(
            $path,
            <<<'PHP'
                <?php
                use Amp\Mysql\MysqlLink;
                use Amp\Postgres\PostgresLink;
                use Kinetis\Migrations\Migration;

                return new class implements Migration {
                    public MysqlLink|PostgresLink|null $receivedOnUp = null;
                    public MysqlLink|PostgresLink|null $receivedOnDown = null;

                    public function up(MysqlLink|PostgresLink $db): void
                    {
                        $this->receivedOnUp = $db;
                    }

                    public function down(MysqlLink|PostgresLink $db): void
                    {
                        $this->receivedOnDown = $db;
                    }
                };
                PHP,
        );

        $file = new MigrationFile('20260101000000_records_db', $path);
        $migration = $file->load();
        $db = new FakeMysqlLink();

        $migration->up($db);
        self::assertSame($db, $migration->receivedOnUp);

        $migration->down($db);
        self::assertSame($db, $migration->receivedOnDown);
    }
}
