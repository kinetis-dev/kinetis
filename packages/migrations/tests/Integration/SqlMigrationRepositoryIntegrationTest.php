<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Tests\Integration;

use Kinetis\Migrations\SqlMigrationRepository;
use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\Driver\PdoMysqlClient;
use PHPUnit\Framework\TestCase;

/**
 * The repository against a real MySQL. MigrationRunner's own ordering
 * logic is unit-tested against InMemoryMigrationRepository; what only a
 * real server can show is that this class's bookkeeping SQL does what
 * the runner assumes — that the table creates idempotently, and that
 * lastApplied() answers by migration name rather than by insertion
 * order or applied_at.
 *
 * Environment-gated on MYSQL_HOST, like every other real-backend test in
 * this repository.
 */
final class SqlMigrationRepositoryIntegrationTest extends TestCase
{
    private ?SqlLink $link = null;

    private static function client(): SqlLink
    {
        $host = \getenv('MYSQL_HOST');

        if ($host === false || $host === '') {
            self::markTestSkipped('MYSQL_HOST is not set — real-backend migration tests are environment-gated.');
        }

        return new PdoMysqlClient(
            $host,
            \getenv('MYSQL_USER') ?: 'testuser',
            \getenv('MYSQL_PASSWORD') ?: 'testpass',
            \getenv('MYSQL_DATABASE') ?: 'testdb',
            (int) (\getenv('MYSQL_PORT') ?: 3306),
        );
    }

    protected function setUp(): void
    {
        $this->link = self::client();
        $this->link->execute('DROP TABLE IF EXISTS kinetis_migrations');
    }

    protected function tearDown(): void
    {
        $this->link?->close();
        $this->link = null;
    }

    private function repository(): SqlMigrationRepository
    {
        \assert($this->link !== null);

        return new SqlMigrationRepository($this->link);
    }

    /**
     * Every command calls this before anything else, so it has to be
     * safe on an already-migrated database, not only a fresh one.
     */
    public function test_ensure_table_exists_is_safe_to_repeat(): void
    {
        $repository = $this->repository();
        $repository->ensureTableExists();
        $repository->ensureTableExists();

        self::assertSame([], $repository->applied());
    }

    public function test_applied_migrations_round_trip_in_name_order(): void
    {
        $repository = $this->repository();
        $repository->ensureTableExists();

        // Deliberately inserted out of order: the runner needs them back
        // sorted, not in the order they happened to be recorded.
        $repository->markApplied('20260102_create_orders');
        $repository->markApplied('20260101_create_users');

        self::assertSame(['20260101_create_users', '20260102_create_orders'], $repository->applied());
    }

    /**
     * lastApplied() orders by the migration name rather than applied_at
     * on purpose: applied_at has no sub-second precision, so two
     * migrations recorded in the same second cannot be told apart by it,
     * while the timestamped name always can. Recording them in reverse
     * is what makes the two orderings disagree.
     */
    public function test_last_applied_answers_by_name_not_by_when_it_was_recorded(): void
    {
        $repository = $this->repository();
        $repository->ensureTableExists();
        $repository->markApplied('20260102_create_orders');
        $repository->markApplied('20260101_create_users');

        self::assertSame('20260102_create_orders', $repository->lastApplied());
    }

    public function test_last_applied_is_null_on_a_fresh_database(): void
    {
        $repository = $this->repository();
        $repository->ensureTableExists();

        self::assertNull($repository->lastApplied());
    }

    public function test_rolling_back_removes_only_that_migration(): void
    {
        $repository = $this->repository();
        $repository->ensureTableExists();
        $repository->markApplied('20260101_create_users');
        $repository->markApplied('20260102_create_orders');

        $repository->markRolledBack('20260102_create_orders');

        self::assertSame(['20260101_create_users'], $repository->applied());
        self::assertSame('20260101_create_users', $repository->lastApplied());
    }
}
