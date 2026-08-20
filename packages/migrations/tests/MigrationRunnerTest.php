<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Tests;

use Kinetis\Migrations\Exception\MigrationFileMissingException;
use Kinetis\Migrations\Exception\MigrationLockTimeoutException;
use Kinetis\Migrations\MigrationRunner;
use Kinetis\Migrations\Tests\Fixtures\FakeMysqlLink;
use Kinetis\Migrations\Tests\Fixtures\FakePostgresLink;
use Kinetis\Migrations\Tests\Fixtures\InMemoryMigrationRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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
                use Kinetis\Persistence\Contract\MysqlLink;
                use Kinetis\Persistence\Contract\PostgresLink;
                use Kinetis\Migrations\Migration;

                return new class implements Migration {
                    public function up(MysqlLink|PostgresLink $db): void {}
                    public function down(MysqlLink|PostgresLink $db): void {}
                };
                PHP,
        );
    }

    private function writeThrowingMigration(string $name): void
    {
        file_put_contents(
            $this->migrationsPath . "/{$name}.php",
            <<<'PHP'
                <?php
                use Kinetis\Persistence\Contract\MysqlLink;
                use Kinetis\Persistence\Contract\PostgresLink;
                use Kinetis\Migrations\Migration;

                return new class implements Migration {
                    public function up(MysqlLink|PostgresLink $db): void {
                        throw new RuntimeException('deliberate failure');
                    }
                    public function down(MysqlLink|PostgresLink $db): void {}
                };
                PHP,
        );
    }

    /** down() writes $markerPath to disk, so a test can confirm it actually ran without needing $db to observe anything. */
    private function writeMigrationWithObservableDown(string $name, string $markerPath): void
    {
        file_put_contents(
            $this->migrationsPath . "/{$name}.php",
            <<<PHP
                <?php
                use Kinetis\\Persistence\\Contract\\MysqlLink;
                use Kinetis\\Persistence\\Contract\\PostgresLink;
                use Kinetis\\Migrations\\Migration;

                return new class implements Migration {
                    public function up(MysqlLink|PostgresLink \$db): void {}
                    public function down(MysqlLink|PostgresLink \$db): void {
                        file_put_contents('{$markerPath}', 'down ran');
                    }
                };
                PHP,
        );
    }

    private function runner(InMemoryMigrationRepository $repository, ?FakeMysqlLink $link = null): MigrationRunner
    {
        return new MigrationRunner($link ?? new FakeMysqlLink(), $repository, $this->migrationsPath);
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

    public function test_rollback_ensures_the_tracking_table_exists(): void
    {
        $this->writeMigration('20260101000000_first');
        $repository = new InMemoryMigrationRepository();
        $repository->markApplied('20260101000000_first');
        self::assertFalse($repository->tableEnsured);

        $this->runner($repository)->rollback();

        self::assertTrue($repository->tableEnsured);
    }

    public function test_rollback_actually_invokes_the_migrations_own_down_method(): void
    {
        // Outside $this->migrationsPath deliberately -- tearDown() rmdir()s
        // that directory, which would fail if a leftover marker file (say,
        // from a failed assertion never reaching the unlink() below) were
        // still sitting inside it.
        $markerPath = sys_get_temp_dir() . '/kinetis-migrations-down-marker-' . bin2hex(random_bytes(8));
        $this->writeMigrationWithObservableDown('20260101000000_first', $markerPath);
        $repository = new InMemoryMigrationRepository();
        $repository->markApplied('20260101000000_first');

        try {
            self::assertFileDoesNotExist($markerPath);

            $this->runner($repository)->rollback();

            self::assertFileExists($markerPath);
        } finally {
            @unlink($markerPath);
        }
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

    public function test_pending_ensures_the_tracking_table_exists(): void
    {
        $repository = new InMemoryMigrationRepository();
        self::assertFalse($repository->tableEnsured);

        $this->runner($repository)->pending();

        self::assertTrue($repository->tableEnsured);
    }

    public function test_status_ensures_the_tracking_table_exists(): void
    {
        $repository = new InMemoryMigrationRepository();
        self::assertFalse($repository->tableEnsured);

        $this->runner($repository)->status();

        self::assertTrue($repository->tableEnsured);
    }

    public function test_migrate_acquires_and_releases_the_mysql_advisory_lock(): void
    {
        $link = new FakeMysqlLink();
        $this->writeMigration('20260101000000_first');

        $this->runner(new InMemoryMigrationRepository(), $link)->migrate();

        self::assertTrue($link->lockAcquired);
        self::assertTrue($link->lockReleased);
    }

    public function test_rollback_acquires_and_releases_the_mysql_advisory_lock(): void
    {
        $link = new FakeMysqlLink();
        $this->writeMigration('20260101000000_first');
        $repository = new InMemoryMigrationRepository();
        $repository->markApplied('20260101000000_first');

        $this->runner($repository, $link)->rollback();

        self::assertTrue($link->lockAcquired);
        self::assertTrue($link->lockReleased);
    }

    public function test_migrate_throws_a_clear_timeout_when_the_mysql_lock_cannot_be_acquired(): void
    {
        $link = new FakeMysqlLink();
        $link->lockAcquireShouldTimeOut = true;

        try {
            // The default MigrationRunner constructor (no lockTimeoutSeconds
            // argument) is used deliberately here, not the 5-second one
            // this file's own runner() helper would give it -- pinning
            // the exact message is what confirms the constructor's own
            // default of 10 is actually what's in force, not merely
            // "some number that happens to work."
            new MigrationRunner($link, new InMemoryMigrationRepository(), $this->migrationsPath)->migrate();
            self::fail('Expected MigrationLockTimeoutException.');
        } catch (MigrationLockTimeoutException $e) {
            self::assertSame(
                'Could not acquire the migration lock within 10 second(s) — another process is likely '
                . 'already running migrate() or rollback(). Retry once it finishes, or investigate whether '
                . 'a previous run is genuinely stuck (the lock is held only for the duration of its session, '
                . "so it releases on its own if that process's connection ever closes).",
                $e->getMessage(),
            );
        }
    }

    /**
     * A migration that throws must still release the lock — otherwise a
     * failed deploy leaves every later attempt (including a legitimate
     * retry) unable to acquire it for the rest of that session.
     */
    public function test_the_lock_is_released_even_when_a_migration_throws(): void
    {
        $link = new FakeMysqlLink();
        $this->writeThrowingMigration('20260101000000_boom');

        try {
            $this->runner(new InMemoryMigrationRepository(), $link)->migrate();
            self::fail('Expected the migration\'s own exception to propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('deliberate failure', $e->getMessage());
        }

        self::assertTrue($link->lockAcquired);
        self::assertTrue($link->lockReleased);
    }

    /**
     * str_contains() (what FakeMysqlLink's own dispatch uses to route
     * these calls) can't tell a correct GET_LOCK(?, ?)/RELEASE_LOCK(?)
     * call apart from one with a dropped or reordered bound param — this
     * asserts the exact SQL and params sent for both, in the order
     * migrate() actually issues them.
     */
    public function test_migrate_sends_the_exact_get_lock_and_release_lock_sql(): void
    {
        $link = new FakeMysqlLink();
        $this->writeMigration('20260101000000_first');

        $this->runner(new InMemoryMigrationRepository(), $link)->migrate();

        self::assertSame(
            [
                ['sql' => 'SELECT GET_LOCK(?, ?) AS acquired', 'params' => ['kinetis_migrations', 10]],
                ['sql' => 'SELECT RELEASE_LOCK(?)', 'params' => ['kinetis_migrations']],
            ],
            $link->calls,
        );
    }

    /**
     * GET_LOCK() genuinely can come back as something other than a
     * native PHP int depending on the driver — the (int) cast in
     * acquireLock() is what makes that safe. A string "1" that
     * assertSame(1, ...) would treat as identical-and-therefore-untested
     * proves the cast is load-bearing rather than incidental.
     */
    public function test_mysql_lock_acquisition_tolerates_a_non_native_int_acquired_value(): void
    {
        $link = new FakeMysqlLink();
        $link->acquiredValue = '1';
        $this->writeMigration('20260101000000_first');

        $applied = $this->runner(new InMemoryMigrationRepository(), $link)->migrate();

        self::assertSame(['20260101000000_first'], $applied);
    }

    public function test_migrate_acquires_the_postgres_advisory_lock_after_a_poll_retry(): void
    {
        $link = new FakePostgresLink(acquiresAfterAttempts: 3);
        $this->writeMigration('20260101000000_first');
        $runner = new MigrationRunner($link, new InMemoryMigrationRepository(), $this->migrationsPath, lockTimeoutSeconds: 5);

        $applied = $runner->migrate();

        self::assertSame(['20260101000000_first'], $applied);
        self::assertTrue($link->lockReleased);
    }

    /**
     * pg_try_advisory_lock/pg_advisory_unlock's own namespace and key
     * arguments are plain concatenated integers, not bound params (see
     * MigrationRunner's own docblock on PG_LOCK_NAMESPACE/PG_LOCK_KEY) —
     * str_contains() alone can't tell the right values apart from wrong
     * ones, so this asserts the exact SQL text sent for both calls.
     */
    public function test_migrate_sends_the_exact_postgres_advisory_lock_sql(): void
    {
        $link = new FakePostgresLink();
        $this->writeMigration('20260101000000_first');
        $runner = new MigrationRunner($link, new InMemoryMigrationRepository(), $this->migrationsPath);

        $runner->migrate();

        self::assertSame(
            [
                'SELECT pg_try_advisory_lock(870124, 1)::int AS acquired',
                'SELECT pg_advisory_unlock(870124, 1)',
            ],
            $link->calls,
        );
    }

    /**
     * The same (int) cast concern as the MySQL case above — Postgres's
     * own boolean representation genuinely differs between the native
     * and PDO drivers (this class's own docblock says so directly), so a
     * non-native-int acquired value has to still be recognized.
     */
    public function test_postgres_lock_acquisition_tolerates_a_non_native_int_acquired_value(): void
    {
        $link = new FakePostgresLink();
        $link->acquiredValue = '1';
        $this->writeMigration('20260101000000_first');
        // A short, explicit timeout: without the (int) cast this class's
        // own comment discloses is load-bearing, "1" !== 1 forever, so
        // this would otherwise poll for the full timeout before failing
        // -- bounding it here keeps that failure mode fast rather than
        // a real multi-second wait.
        $runner = new MigrationRunner($link, new InMemoryMigrationRepository(), $this->migrationsPath, lockTimeoutSeconds: 1);

        $applied = $runner->migrate();

        self::assertSame(['20260101000000_first'], $applied);
    }

    public function test_migrate_throws_a_clear_timeout_when_the_postgres_lock_never_acquires(): void
    {
        // acquiresAfterAttempts far beyond what a 1-second timeout can
        // possibly reach at the runner's 100ms poll interval.
        $link = new FakePostgresLink(acquiresAfterAttempts: 1000);
        $runner = new MigrationRunner($link, new InMemoryMigrationRepository(), $this->migrationsPath, lockTimeoutSeconds: 1);

        $this->expectException(MigrationLockTimeoutException::class);
        $this->expectExceptionMessage('Could not acquire the migration lock');

        $runner->migrate();
    }
}
