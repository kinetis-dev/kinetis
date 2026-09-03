<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests\Integration;

use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\Driver\PdoMysqlClient;
use Kinetis\Session\Store\SqlSessionStore;
use Kinetis\Session\Support\SessionExpiry;
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

        // The shipped migration stub's own DDL — DATETIME, not TIMESTAMP;
        // see SqlSessionStore's own class docblock and KINETIS-69's tests
        // below for why.
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
        $this->writeExpiredRow('sid-4', ['user' => 42]);

        self::assertNull($this->store()->read('sid-4'));
        self::assertSame(1, $this->rowCount(), 'the row is still present, only unreadable');
    }

    /**
     * KINETIS-68: a session expiring at exactly the current second is
     * already expired — confirmed here against a real server's own
     * NOW(), not just the fake-link SQL-text assertions
     * SqlSessionStoreTest carries for this same boundary.
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
     * KINETIS-68 FEEDBACK: SessionExpiry::MAX_EXPIRES_AT was derived
     * directly from a real MySQL server's own actual TIMESTAMP rejection
     * (an INSERT one second past it fails with a genuine "Incorrect
     * datetime value" error, confirmed by hand before this constant was
     * chosen) — this proves write() itself, through the real store, can
     * actually store and read back a session comfortably close to that
     * value against a live server, not just that the constant matches a
     * number verified once by hand.
     *
     * KINETIS-68 FEEDBACK 2: a safe 100-second margin under the maximum,
     * not landing exactly at it — write() has no injectable clock, so
     * this test's own time() call and the one inside SessionExpiry's own
     * timestampFor() are two genuinely separate clock reads a slow or
     * preempted process could let tick over between, and the value this
     * test derives is also what a real MySQL row is meant to store, not
     * merely compared in memory. The exact boundary itself is proven
     * deterministically, with zero real-clock involvement, by
     * SessionExpiryTest's own timestampFor() tests.
     */
    public function test_a_lifetime_comfortably_under_the_portable_maximum_round_trips(): void
    {
        $lifetime = SessionExpiry::MAX_EXPIRES_AT - \time() - 100;

        $this->store()->write('sid-max', ['user' => 42], $lifetime);

        self::assertSame(['user' => 42], $this->store()->read('sid-max'));
    }

    /**
     * KINETIS-69: MySQL's TIMESTAMP column reinterprets a bound literal
     * through the connection's own session time_zone — confirmed
     * directly against a real server before this fix existed: the exact
     * same literal string SqlSessionStore writes stores a materially
     * different absolute instant depending on that setting. The shipped
     * schema uses DATETIME specifically because it does not — this
     * proves a session written and read entirely under one non-UTC
     * session survives correctly, on a genuinely hostile (not default)
     * connection setting.
     *
     * A dedicated connection, never $this->link from setUp() — the
     * timezone change must stay scoped to this one test's own
     * connection, never a shared one, matching what SqlSessionStore's
     * own docblock says this package itself must never do to an
     * application's connection.
     */
    public function test_a_session_round_trips_correctly_under_a_non_utc_session_timezone(): void
    {
        $link = self::client();
        $link->execute("SET time_zone = '+05:00'");

        try {
            $store = new SqlSessionStore($link);
            $store->write('sid-tz-plus5', ['user' => 42], 3600);

            self::assertSame(['user' => 42], $store->read('sid-tz-plus5'));
        } finally {
            $link->close();
        }
    }

    /**
     * KINETIS-69: the specific, severe failure mode the timezone
     * dependency produced under the old TIMESTAMP-based schema —
     * confirmed directly against a real server before this fix existed:
     * a lifetime landing well within SessionExpiry::MAX_EXPIRES_AT was
     * rejected outright by MySQL with a genuine "Incorrect datetime
     * value" error under a negative-offset session, because
     * reinterpreting the literal wall-clock string through that offset
     * pushed the *effective* stored instant past what TIMESTAMP could
     * hold — even though the value the application believed it was
     * storing was nowhere near that limit. DATETIME has no such
     * reinterpretation, so this must now succeed cleanly.
     */
    public function test_a_lifetime_comfortably_under_the_portable_maximum_round_trips_under_a_negative_offset_session(): void
    {
        $link = self::client();
        $link->execute("SET time_zone = '-05:00'");

        try {
            $store = new SqlSessionStore($link);
            $lifetime = SessionExpiry::MAX_EXPIRES_AT - \time() - 100;
            $store->write('sid-tz-minus5-max', ['user' => 42], $lifetime);

            self::assertSame(['user' => 42], $store->read('sid-tz-minus5-max'));
        } finally {
            $link->close();
        }
    }

    /**
     * KINETIS-69: proves the *stored value itself* is not shifted by the
     * writing connection's own session timezone — a real, DST-observing
     * named IANA zone (not a bare numeric offset), so this also confirms
     * the shipped schema is correct against a genuine tz-database-backed
     * session setting, not only a fixed-offset one.
     *
     * Deliberately not a payload round-trip through read() alone —
     * caught empirically, not assumed, while writing this test: under
     * the old TIMESTAMP-based schema, writing under America/New_York
     * then reading through store()->read() under a *different* zone
     * (Pacific/Auckland) still returned the correct payload despite the
     * stored instant genuinely having been shifted by several hours,
     * because both the write-time reinterpretation and the read-time
     * `expires_at > now` comparison get reinterpreted consistently
     * enough, for this particular pair of offsets, that the inequality
     * still happens to hold — a false pass that depends on which two
     * offsets and what real wall-clock instant the test happens to run
     * at, not a reliable proof either way. Reading the *raw* stored
     * value back through a separate, explicitly UTC-forced connection
     * instead directly detects any reinterpretation-driven shift, in
     * either direction, regardless of which zone wrote it or when.
     *
     * The window is a tolerant bound, not exact equality — two
     * genuinely separate real time() calls straddle the write, the same
     * "read the clock before and after, assert the value lands between"
     * shape SessionExpiryTest::test_timestamp_for_is_now_plus_the_lifetime
     * already establishes; string comparison is valid here because
     * `Y-m-d H:i:s` sorts identically to chronological order.
     */
    public function test_the_stored_value_is_not_shifted_by_the_writing_connections_session_timezone(): void
    {
        $writeLink = self::client();
        $writeLink->execute("SET time_zone = 'America/New_York'");

        $before = \gmdate('Y-m-d H:i:s', \time() + 3600);

        try {
            new SqlSessionStore($writeLink)->write('sid-tz-shift-check', ['user' => 42], 3600);
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
        $store->write('sid-5', ['user' => 42], 3600);
        $store->destroy('sid-5');

        self::assertNull($store->read('sid-5'));
        self::assertSame(0, $this->rowCount());
    }

    public function test_gc_removes_only_what_has_expired_and_reports_how_many(): void
    {
        $store = $this->store();
        $store->write('sid-live', ['a' => 1], 3600);
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
     * write() itself now rejects a non-positive $lifetimeSeconds
     * (KINETIS-68), so an already-expired row for a test to observe is
     * inserted directly, one real second in the past via the server's
     * own NOW() — bypassing write()'s own contract entirely, the same
     * way the file-store tests seed an expired file directly.
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
