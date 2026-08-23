<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests\Integration;

use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\Driver\PdoMysqlClient;
use Kinetis\Session\Store\SqlSessionStore;
use PHPUnit\Framework\Attributes\BeforeClass;
use PHPUnit\Framework\TestCase;

/**
 * SqlSessionStore against a real MySQL, because the one thing worth
 * pinning here is not expressible against a fake: the store writes with
 * an UPDATE followed by an INSERT, and whether that is correct depends
 * on how the server counts affected rows.
 *
 * Environment-gated on MYSQL_HOST, like every other real-backend test in
 * this repository.
 */
final class SqlSessionStoreIntegrationTest extends TestCase
{
    private ?SqlLink $link = null;

    private static function client(): SqlLink
    {
        $host = \getenv('MYSQL_HOST');

        if ($host === false || $host === '') {
            self::markTestSkipped('MYSQL_HOST is not set — real-backend session tests are environment-gated.');
        }

        return new PdoMysqlClient(
            $host,
            \getenv('MYSQL_USER') ?: 'testuser',
            \getenv('MYSQL_PASSWORD') ?: 'testpass',
            \getenv('MYSQL_DATABASE') ?: 'testdb',
            (int) (\getenv('MYSQL_PORT') ?: 3306),
        );
    }

    #[BeforeClass]
    public static function createTable(): void
    {
        if (\getenv('MYSQL_HOST') === false) {
            return;
        }

        // The shipped migration stub's own DDL.
        $link = self::client();
        $link->query('CREATE TABLE IF NOT EXISTS kinetis_sessions (
            id VARCHAR(64) PRIMARY KEY,
            payload TEXT NOT NULL,
            expires_at TIMESTAMP NOT NULL,
            INDEX kinetis_sessions_expires_at_index (expires_at)
        )');
        $link->close();
    }

    protected function setUp(): void
    {
        $this->link = self::client();
        $this->link->execute('DELETE FROM kinetis_sessions');
    }

    protected function tearDown(): void
    {
        $this->link?->close();
        $this->link = null;
    }

    private function store(): SqlSessionStore
    {
        \assert($this->link !== null);

        return new SqlSessionStore($this->link);
    }

    public function test_a_session_round_trips(): void
    {
        $this->store()->write('sid-1', ['user' => 42, 'theme' => 'dark'], 3600);

        self::assertSame(['user' => 42, 'theme' => 'dark'], $this->store()->read('sid-1'));
    }

    public function test_reading_a_session_that_was_never_written_returns_null(): void
    {
        self::assertNull($this->store()->read('sid-absent'));
    }

    /**
     * The reason this test exists. MySQL reports zero affected rows for
     * an UPDATE whose values are byte-identical to the stored row, so a
     * store that treats "nothing updated" as "no row yet" falls through
     * to an INSERT and collides with its own primary key. Writing the
     * same payload twice is the ordinary case — a request that reads a
     * session and changes nothing about it.
     */
    public function test_writing_identical_data_twice_does_not_collide(): void
    {
        $store = $this->store();
        $store->write('sid-2', ['user' => 42], 3600);
        $store->write('sid-2', ['user' => 42], 3600);

        self::assertSame(['user' => 42], $store->read('sid-2'));
    }

    public function test_a_write_replaces_the_previous_payload(): void
    {
        $store = $this->store();
        $store->write('sid-3', ['step' => 1], 3600);
        $store->write('sid-3', ['step' => 2], 3600);

        self::assertSame(['step' => 2], $store->read('sid-3'));
    }

    /**
     * Expiry is a WHERE clause, not a sweep: an elapsed session reads as
     * absent while its row is still there.
     */
    public function test_an_expired_session_reads_as_absent(): void
    {
        $this->store()->write('sid-4', ['user' => 42], -1);

        self::assertNull($this->store()->read('sid-4'));
        self::assertSame(1, $this->rowCount(), 'the row is still present, only unreadable');
    }

    public function test_destroy_removes_the_row(): void
    {
        $store = $this->store();
        $store->write('sid-5', ['user' => 42], 3600);
        $store->destroy('sid-5');

        self::assertNull($store->read('sid-5'));
        self::assertSame(0, $this->rowCount());
    }

    public function test_gc_removes_only_what_has_expired_and_reports_how_many(): void
    {
        $store = $this->store();
        $store->write('sid-live', ['a' => 1], 3600);
        $store->write('sid-dead-1', ['b' => 2], -1);
        $store->write('sid-dead-2', ['c' => 3], -1);

        self::assertSame(2, $store->gc());
        self::assertSame(['a' => 1], $store->read('sid-live'));
        self::assertSame(1, $this->rowCount());
    }

    private function rowCount(): int
    {
        \assert($this->link !== null);
        $row = $this->link->query('SELECT COUNT(*) AS c FROM kinetis_sessions')->fetchRow();

        return (int) ($row['c'] ?? 0);
    }
}
