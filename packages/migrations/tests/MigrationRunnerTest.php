<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Tests;

use Kinetis\Migrations\Exception\MigrationFileMissingException;
use Kinetis\Migrations\MigrationRunner;
use Kinetis\Migrations\Tests\Fixtures\FakeMysqlLink;
use Kinetis\Migrations\Tests\Fixtures\InMemoryMigrationRepository;
use PHPUnit\Framework\TestCase;

final class MigrationRunnerTest extends TestCase
{
    private string $migrationsPath;

    protected function setUp(): void
    {
        $this->migrationsPath = sys_get_temp_dir() . '/kinetis-migrations-test-' . bin2hex(random_bytes(8));
        mkdir($this->migrationsPath);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->migrationsPath . '/*.php') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->migrationsPath);
    }

    private function writeMigration(string $name): void
    {
        file_put_contents(
            $this->migrationsPath . "/{$name}.php",
            <<<'PHP'
                <?php
                use Amp\Mysql\MysqlLink;
                use Amp\Postgres\PostgresLink;
                use Kinetis\Migrations\Migration;

                return new class implements Migration {
                    public function up(MysqlLink|PostgresLink $db): void {}
                    public function down(MysqlLink|PostgresLink $db): void {}
                };
                PHP,
        );
    }

    private function runner(InMemoryMigrationRepository $repository): MigrationRunner
    {
        return new MigrationRunner(new FakeMysqlLink(), $repository, $this->migrationsPath);
    }

    public function test_pending_returns_migrations_not_yet_applied(): void
    {
        $this->writeMigration('20260101000000_first');
        $this->writeMigration('20260102000000_second');

        $repository = new InMemoryMigrationRepository();
        $repository->markApplied('20260101000000_first');

        $pending = $this->runner($repository)->pending();

        self::assertCount(1, $pending);
        self::assertSame('20260102000000_second', $pending[0]->name);
    }

    public function test_migrate_runs_every_pending_migration_in_filename_order_and_records_it(): void
    {
        $this->writeMigration('20260102000000_second');
        $this->writeMigration('20260101000000_first');

        $repository = new InMemoryMigrationRepository();
        $applied = $this->runner($repository)->migrate();

        self::assertSame(['20260101000000_first', '20260102000000_second'], $applied);
        self::assertSame(['20260101000000_first', '20260102000000_second'], $repository->applied());
    }

    public function test_migrate_with_nothing_pending_returns_an_empty_list(): void
    {
        self::assertSame([], $this->runner(new InMemoryMigrationRepository())->migrate());
    }

    public function test_rollback_targets_the_highest_named_applied_migration(): void
    {
        $this->writeMigration('20260101000000_first');
        $this->writeMigration('20260102000000_second');

        $repository = new InMemoryMigrationRepository();
        $repository->markApplied('20260101000000_first');
        $repository->markApplied('20260102000000_second');

        $rolledBack = $this->runner($repository)->rollback();

        self::assertSame('20260102000000_second', $rolledBack);
        self::assertSame(['20260101000000_first'], $repository->applied());
    }

    public function test_rollback_with_nothing_applied_returns_null(): void
    {
        self::assertNull($this->runner(new InMemoryMigrationRepository())->rollback());
    }

    public function test_rollback_throws_when_the_migration_file_no_longer_exists(): void
    {
        $repository = new InMemoryMigrationRepository();
        $repository->markApplied('20260101000000_deleted_file');

        $this->expectException(MigrationFileMissingException::class);

        $this->runner($repository)->rollback();
    }

    public function test_status_reports_applied_and_pending_migrations(): void
    {
        $this->writeMigration('20260101000000_first');
        $this->writeMigration('20260102000000_second');

        $repository = new InMemoryMigrationRepository();
        $repository->markApplied('20260101000000_first');

        $status = $this->runner($repository)->status();

        self::assertSame(
            [
                ['name' => '20260101000000_first', 'applied' => true],
                ['name' => '20260102000000_second', 'applied' => false],
            ],
            $status,
        );
    }

    public function test_pending_and_status_ensure_the_tracking_table_exists(): void
    {
        $repository = new InMemoryMigrationRepository();
        self::assertFalse($repository->tableEnsured);

        $this->runner($repository)->pending();

        self::assertTrue($repository->tableEnsured);
    }
}
