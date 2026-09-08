<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Tests;

use Kinetis\Migrations\Exception\MigrationIntegrityException;
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

    public function test_checksum_is_the_sha256_of_the_files_contents(): void
    {
        $path = $this->migrationsPath . '/20260101000000_hashed.php';
        file_put_contents($path, '<?php return null;');

        self::assertSame(
            hash('sha256', '<?php return null;'),
            new MigrationFile('20260101000000_hashed', $path)->checksum(),
        );
    }

    public function test_checksum_changes_when_the_file_changes(): void
    {
        $path = $this->migrationsPath . '/20260101000000_edited.php';
        file_put_contents($path, '<?php return null;');
        $file = new MigrationFile('20260101000000_edited', $path);
        $before = $file->checksum();

        file_put_contents($path, '<?php return null; // one more statement');

        self::assertNotSame($before, $file->checksum());
    }

    /**
     * The exception names the migration and nothing else, so the hashing
     * call must not report the path itself either. The handler records
     * every diagnostic PHP's own error reporting would still act on, and
     * an empty list is what keeps the two consistent.
     */
    public function test_an_unreadable_file_throws_without_reporting_its_path(): void
    {
        $path = $this->migrationsPath . '/20260101000000_never_written.php';
        $reported = [];

        set_error_handler(static function (int $severity, string $message) use (&$reported): bool {
            // Inside an @-suppressed call PHP narrows the reporting level
            // to exactly this mask, so a wider one is a diagnostic that
            // reached the error log or output.
            $suppressed = E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR | E_PARSE;

            if ((error_reporting() & ~$suppressed) !== 0) {
                $reported[] = $message;
            }

            return true;
        });

        try {
            new MigrationFile('20260101000000_never_written', $path)->checksum();
            self::fail('Expected MigrationIntegrityException.');
        } catch (MigrationIntegrityException $e) {
            self::assertStringContainsString('20260101000000_never_written', $e->getMessage());
            self::assertStringNotContainsString($this->migrationsPath, $e->getMessage());
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $reported);
    }

    public function test_load_returns_a_migration_whose_up_and_down_receive_the_db_argument(): void
    {
        $path = $this->migrationsPath . '/20260101000000_records_db.php';

        file_put_contents(
            $path,
            <<<'PHP'
                <?php
                use Kinetis\Persistence\Contract\MysqlLink;
                use Kinetis\Persistence\Contract\PostgresLink;
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
