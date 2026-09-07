<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Tests\Integration;

use Kinetis\Migrations\SqlMigrationRepository;
use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\Driver\PdoMysqlClient;
use Kinetis\Persistence\Exception\QueryException;
use PHPUnit\Framework\TestCase;

/**
 * The repository against a real MySQL. MigrationRunner's own ordering and
 * integrity logic is unit-tested against InMemoryMigrationRepository;
 * what only a real server can show is that this class's bookkeeping SQL
 * does what the runner assumes — that the table creates idempotently,
 * that application_order really is assigned from the table's own current
 * maximum, and that a name recorded twice is refused by the primary key
 * rather than written.
 *
 * Environment-gated on MYSQL_HOST, like every other real-backend test in
 * this repository.
 */
final class SqlMigrationRepositoryIntegrationTest extends TestCase
{
    private const CHECKSUM_USERS = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';
    private const CHECKSUM_ORDERS = '0f9e8d7c6b5a49382716f5e4d3c2b1a00f9e8d7c6b5a49382716f5e4d3c2b1a0';

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
     * @return list<int>
     */
    private function applicationOrders(): array
    {
        \assert($this->link !== null);

        $orders = [];

        foreach ($this->link->execute('SELECT application_order FROM kinetis_migrations ORDER BY application_order ASC') as $row) {
            $orders[] = (int) $row['application_order'];
        }

        return $orders;
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

    /**
     * Recorded out of name order deliberately: the runner needs them back
     * in the order this database applied them, each with the checksum
     * stored for it, which is what its integrity check compares against.
     */
    public function test_applied_migrations_round_trip_in_application_order_with_their_checksums(): void
    {
        $repository = $this->repository();
        $repository->ensureTableExists();

        $repository->markApplied('20260102_create_orders', self::CHECKSUM_ORDERS);
        $repository->markApplied('20260101_create_users', self::CHECKSUM_USERS);

        self::assertSame(
            [
                '20260102_create_orders' => self::CHECKSUM_ORDERS,
                '20260101_create_users' => self::CHECKSUM_USERS,
            ],
            $repository->applied(),
        );
    }

    /**
     * The order column comes from the table's own COALESCE(MAX(...), 0) +
     * 1 in the insert itself: 1 on an empty table, and one past the
     * highest row after that — including after a rollback freed the
     * position it used, which a migration recorded again then lands past
     * rather than back in.
     */
    public function test_application_order_counts_up_from_the_current_maximum(): void
    {
        $repository = $this->repository();
        $repository->ensureTableExists();

        $repository->markApplied('20260101_create_users', self::CHECKSUM_USERS);
        $repository->markApplied('20260102_create_orders', self::CHECKSUM_ORDERS);
        self::assertSame([1, 2], $this->applicationOrders());

        $repository->markRolledBack('20260102_create_orders');
        $repository->markApplied('20260102_create_orders', self::CHECKSUM_ORDERS);

        self::assertSame([1, 3], $this->applicationOrders());
        self::assertSame(
            ['20260101_create_users', '20260102_create_orders'],
            array_keys($repository->applied()),
        );
    }

    /**
     * One row per migration name, enforced by the primary key: a second
     * record for the same name is a failed insert, never a duplicate the
     * runner would then see twice.
     */
    public function test_recording_the_same_migration_twice_is_refused(): void
    {
        $repository = $this->repository();
        $repository->ensureTableExists();
        $repository->markApplied('20260101_create_users', self::CHECKSUM_USERS);

        try {
            $repository->markApplied('20260101_create_users', self::CHECKSUM_USERS);
            self::fail('Expected the primary key to refuse a second row for the same migration.');
        } catch (QueryException) {
            // Expected.
        }

        self::assertSame([1], $this->applicationOrders());
    }

    public function test_rolling_back_removes_only_that_migration(): void
    {
        $repository = $this->repository();
        $repository->ensureTableExists();
        $repository->markApplied('20260101_create_users', self::CHECKSUM_USERS);
        $repository->markApplied('20260102_create_orders', self::CHECKSUM_ORDERS);

        $repository->markRolledBack('20260102_create_orders');

        self::assertSame(['20260101_create_users' => self::CHECKSUM_USERS], $repository->applied());
    }
}
