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
 * pinning here is not expressible against a fake: update() reads the
 * server's own affected-row count, and MySQL counts changed rows rather
 * than matched ones.
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

        // The shipped migration stub's DDL — DATETIME, not TIMESTAMP;
        // see SqlSessionStore's class docblock for why.
        $link = self::client();
        $link->query('CREATE TABLE IF NOT EXISTS kinetis_sessions (
            id VARCHAR(64) PRIMARY KEY,
            payload TEXT NOT NULL,
            expires_at DATETIME NOT NULL,
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
        $this->store()->create('sid-1', ['user' => 42, 'theme' => 'dark'], 3600);

        self::assertSame(['user' => 42, 'theme' => 'dark'], $this->store()->read('sid-1'));
    }

    public function test_reading_a_session_that_was_never_written_returns_null(): void
    {
        self::assertNull($this->store()->read('sid-absent'));
    }

    /**
     * The reason this test exists. MySQL reports zero affected rows for
     * an UPDATE whose values are byte-identical to the stored row — the
     * ordinary case of a request that read a session and changed
     * nothing in it. Reading that as "the record is gone" would discard
     * a live session and drop its cookie.
     */
    public function test_an_update_that_changes_no_bytes_still_reports_the_live_record(): void
    {
        $store = $this->store();
        $store->create('sid-2', ['user' => 42], 3600);

        self::assertTrue($store->update('sid-2', ['user' => 42], 3600));
        self::assertSame(['user' => 42], $store->read('sid-2'));
    }

    public function test_an_update_replaces_the_previous_payload(): void
    {
        $store = $this->store();
        $store->create('sid-3', ['step' => 1], 3600);

        self::assertTrue($store->update('sid-3', ['step' => 2], 3600));
        self::assertSame(['step' => 2], $store->read('sid-3'));
    }

    public function test_an_update_after_the_record_was_destroyed_is_refused(): void
    {
        $store = $this->store();
        $store->create('sid-terminal', ['user' => 42], 3600);
        $store->destroy('sid-terminal');

        self::assertFalse($store->update('sid-terminal', ['user' => 42], 3600));
        self::assertNull($store->read('sid-terminal'));
        self::assertSame(0, $this->rowCount(), 'a refused update must not recreate the row.');
    }

    public function test_an_update_against_an_expired_row_is_refused(): void
    {
        $this->writeExpiredRow('sid-expired', ['user' => 42]);

        self::assertFalse($this->store()->update('sid-expired', ['user' => 43], 3600));
        self::assertNull($this->store()->read('sid-expired'));
    }

    /**
     * Expiry is a WHERE clause, not a sweep: an elapsed session reads as
     * absent while its row is still there.
     */
    public function test_an_expired_session_reads_as_absent(): void
    {
        $this->writeExpiredRow('sid-4', ['user' => 42]);

        self::assertNull($this->store()->read('sid-4'));
        self::assertSame(1, $this->rowCount(), 'the row is still present, only unreadable');
    }

    /**
     * A session expiring at exactly the current second is already
     * expired, here against a real server's own NOW() rather than the
     * SQL-text assertion SqlSessionStoreTest makes for this boundary.
     */
    public function test_a_session_expiring_exactly_now_reads_as_absent(): void
    {
        \assert($this->link !== null);
        $payload = \json_encode(['user' => 42], \JSON_THROW_ON_ERROR);
        $this->link->execute(
            'INSERT INTO kinetis_sessions (id, payload, expires_at) VALUES (?, ?, NOW())',
            ['sid-boundary', $payload],
        );

        self::assertNull($this->store()->read('sid-boundary'));
    }

    /**
     * MySQL's TIMESTAMP column reinterprets a bound literal through the
     * connection's session time_zone; the shipped schema uses DATETIME,
     * which does not. A session written and read entirely under one
     * non-UTC session must survive intact.
     *
     * A dedicated connection, never $this->link from setUp(): the
     * timezone change stays scoped to this test, matching what
     * SqlSessionStore's docblock says the package must never do to an
     * application's connection.
     */
    public function test_a_session_round_trips_correctly_under_a_non_utc_session_timezone(): void
    {
        $link = self::client();
        $link->execute("SET time_zone = '+05:00'");

        try {
            $store = new SqlSessionStore($link);
            $store->create('sid-tz-plus5', ['user' => 42], 3600);

            self::assertSame(['user' => 42], $store->read('sid-tz-plus5'));
        } finally {
            $link->close();
        }
    }

    /**
     * The stored value itself is not shifted by the writing
     * connection's session timezone — a DST-observing named IANA zone,
     * not a bare numeric offset, so the schema is exercised against a
     * tz-database-backed setting too.
     *
     * The raw stored value is read back through a separate,
     * explicitly UTC-forced connection rather than through read(): a
     * payload round trip can pass while the stored instant is shifted,
     * because a write-time reinterpretation and the read-time
     * `expires_at > now` comparison can shift together and leave the
     * inequality holding. The window is a tolerant bound — two clock
     * reads straddle the write — and string comparison is valid because
     * `Y-m-d H:i:s` sorts chronologically.
     */
    public function test_the_stored_value_is_not_shifted_by_the_writing_connections_session_timezone(): void
    {
        $writeLink = self::client();
        $writeLink->execute("SET time_zone = 'America/New_York'");

        $before = \gmdate('Y-m-d H:i:s', \time() + 3600);

        try {
            new SqlSessionStore($writeLink)->create('sid-tz-shift-check', ['user' => 42], 3600);
        } finally {
            $writeLink->close();
        }

        $after = \gmdate('Y-m-d H:i:s', \time() + 3600);

        $verifyLink = self::client();
        $verifyLink->execute("SET time_zone = '+00:00'");

        try {
            $row = $verifyLink
                ->execute('SELECT expires_at FROM kinetis_sessions WHERE id = ?', ['sid-tz-shift-check'])
                ->fetchRow();

            self::assertIsString($row['expires_at'] ?? null);
            self::assertGreaterThanOrEqual($before, $row['expires_at']);
            self::assertLessThanOrEqual($after, $row['expires_at']);
        } finally {
            $verifyLink->close();
        }
    }

    public function test_destroy_removes_the_row(): void
    {
        $store = $this->store();
        $store->create('sid-5', ['user' => 42], 3600);
        $store->destroy('sid-5');

        self::assertNull($store->read('sid-5'));
        self::assertSame(0, $this->rowCount());
    }

    public function test_gc_removes_only_what_has_expired_and_reports_how_many(): void
    {
        $store = $this->store();
        $store->create('sid-live', ['a' => 1], 3600);
        $this->writeExpiredRow('sid-dead-1', ['b' => 2]);
        $this->writeExpiredRow('sid-dead-2', ['c' => 3]);

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

    /**
     * create() rejects a non-positive $lifetimeSeconds, so an
     * already-expired row is inserted directly, one second in the past
     * via the server's own NOW().
     *
     * @param array<string, mixed> $data
     */
    private function writeExpiredRow(string $id, array $data): void
    {
        \assert($this->link !== null);
        $payload = \json_encode($data, \JSON_THROW_ON_ERROR);
        $this->link->execute(
            'INSERT INTO kinetis_sessions (id, payload, expires_at) VALUES (?, ?, NOW() - INTERVAL 1 SECOND)',
            [$id, $payload],
        );
    }
}
