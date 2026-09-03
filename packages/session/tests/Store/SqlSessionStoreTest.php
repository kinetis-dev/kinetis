<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests\Store;

use Kinetis\Persistence\Exception\QueryException;
use Kinetis\Session\Exception\SessionException;
use Kinetis\Session\Store\SqlSessionStore;
use Kinetis\Session\Support\SessionExpiry;
use Kinetis\Session\Tests\Fixtures\FakeSqlRowResult;
use Kinetis\Session\Tests\Fixtures\ScriptedSqlLink;
use PHPUnit\Framework\TestCase;

/**
 * SqlSessionStore::write()'s UPDATE-then-INSERT-then-retry-then-verify
 * sequencing, proven deterministically against a scripted fake — a real
 * database's own affected-row-count/duplicate-key behavior is what
 * SqlSessionStoreIntegrationTest exists to prove instead; a fake can't
 * (and shouldn't try to) simulate that authentically.
 */
final class SqlSessionStoreTest extends TestCase
{
    private const string UPDATE_SQL = 'UPDATE kinetis_sessions SET payload = ?, expires_at = ? WHERE id = ?';

    private const string INSERT_SQL = 'INSERT INTO kinetis_sessions (id, payload, expires_at) VALUES (?, ?, ?)';

    private const string VERIFY_SQL = 'SELECT id FROM kinetis_sessions WHERE id = ? AND payload = ? AND expires_at = ?';

    /**
     * KINETIS-68: the shared expiry-boundary contract, confirmed against
     * the exact SQL text read() and gc() issue — expires_at > now for a
     * live read, expires_at <= now for gc()'s own deletion — the same
     * boundary FileSessionStore's own read()/gc() now match exactly.
     */
    public function test_read_uses_a_strictly_greater_than_expiry_boundary(): void
    {
        $link = new ScriptedSqlLink([new FakeSqlRowResult(row: null)]);

        new SqlSessionStore($link)->read('sid-read');

        self::assertCount(1, $link->executed);
        self::assertStringContainsString('expires_at > ?', $link->executed[0][0]);
    }

    public function test_gc_uses_the_matching_less_than_or_equal_expiry_boundary(): void
    {
        $link = new ScriptedSqlLink([new FakeSqlRowResult(rowCount: 0)]);

        new SqlSessionStore($link)->gc();

        self::assertCount(1, $link->executed);
        self::assertStringContainsString('expires_at <= ?', $link->executed[0][0]);
    }

    /**
     * KINETIS-69: the exact SQL value write() binds for expires_at —
     * not just the SQL text shape the other tests here already cover.
     * Must be a bare `Y-m-d H:i:s` literal with no timezone marker at
     * all (no 'Z' suffix, no '+00:00'/'-05:00' offset, no ISO-8601 'T'
     * separator) — that's what makes it safe to bind against a
     * timezone-naive column (MySQL's DATETIME, Postgres's TIMESTAMP
     * without time zone): a value carrying its own embedded offset
     * would be redundant at best against a column type that has no
     * concept of one, and outright wrong if it were ever bound against
     * MySQL's own TIMESTAMP, which reinterprets a literal through the
     * connection's session timezone regardless of what the string itself
     * claims. A tolerant, real-clock-bracketed window rather than exact
     * equality — write() has no injectable clock — the same pattern
     * SessionExpiryTest::test_timestamp_for_is_now_plus_the_lifetime
     * already establishes; string comparison is valid for the bound
     * check because `Y-m-d H:i:s` sorts identically to chronological
     * order.
     */
    public function test_write_binds_a_bare_utc_wall_clock_string_with_no_timezone_marker(): void
    {
        $link = new ScriptedSqlLink([new FakeSqlRowResult(rowCount: 1)]);

        $before = \gmdate('Y-m-d H:i:s', \time() + 3600);
        new SqlSessionStore($link)->write('sid-format', ['user' => 42], 3600);
        $after = \gmdate('Y-m-d H:i:s', \time() + 3600);

        [, $params] = $link->executed[0];
        $expiresAt = $params[1];

        self::assertIsString($expiresAt);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $expiresAt);
        self::assertGreaterThanOrEqual($before, $expiresAt);
        self::assertLessThanOrEqual($after, $expiresAt);
    }

    /**
     * KINETIS-68: a non-positive lifetime must be rejected before any
     * SQL statement is ever issued — proven by an empty scripted link
     * that would throw its own RuntimeException the instant execute()
     * was reached, and by confirming $link->executed stayed empty.
     */
    public function test_write_rejects_a_non_positive_lifetime_before_touching_the_database(): void
    {
        $link = new ScriptedSqlLink([]);

        foreach ([0, -1] as $lifetime) {
            try {
                new SqlSessionStore($link)->write('sid-invalid', ['user' => 42], $lifetime);
                self::fail("Expected SessionException for lifetime {$lifetime}.");
            } catch (SessionException $e) {
                self::assertStringContainsString('Session lifetime must be a positive number of seconds', $e->getMessage());
            }
        }

        self::assertSame([], $link->executed, 'an invalid lifetime must never reach the database.');
    }

    /**
     * KINETIS-68: time() + PHP_INT_MAX overflows to a float — write()
     * must reject this before formatTimestamp(int $unix) is ever called
     * with it, rather than letting a raw TypeError escape from deep
     * inside this class instead of a clear, package-owned exception.
     */
    public function test_write_rejects_an_overflowing_lifetime_before_touching_the_database(): void
    {
        $link = new ScriptedSqlLink([]);

        try {
            new SqlSessionStore($link)->write('sid-overflow', ['user' => 42], \PHP_INT_MAX);
            self::fail('Expected SessionException.');
        } catch (SessionException $e) {
            self::assertStringContainsString('produces an expiry beyond', $e->getMessage());
        }

        self::assertSame([], $link->executed, 'an overflowing lifetime must never reach the database.');
    }

    /**
     * KINETIS-68 FEEDBACK: a perfectly ordinary, representable PHP int —
     * no overflow involved — that still pushes the expiry past MySQL's
     * own TIMESTAMP range must be rejected the same way, before the
     * database is ever touched. Distinct from the overflow case above:
     * this proves the portable-maximum check itself.
     *
     * KINETIS-68 FEEDBACK 2: a safe 100-second margin past the maximum,
     * not the razor's-edge +1 this test originally used — write() has no
     * injectable clock, so this test's own time() call and the one
     * inside SessionExpiry::timestampFor() are two genuinely separate
     * clock reads a slow or preempted process could let tick over
     * between. The exact one-second boundary is proven deterministically
     * by SessionExpiryTest's own timestampFor() tests instead, which pin
     * both sides to one hardcoded $now with no real clock involved.
     */
    public function test_write_rejects_a_lifetime_beyond_the_portable_maximum_before_touching_the_database(): void
    {
        $link = new ScriptedSqlLink([]);
        $lifetime = SessionExpiry::MAX_EXPIRES_AT - \time() + 100;

        try {
            new SqlSessionStore($link)->write('sid-beyond-max', ['user' => 42], $lifetime);
            self::fail('Expected SessionException.');
        } catch (SessionException $e) {
            self::assertStringContainsString('produces an expiry beyond', $e->getMessage());
        }

        self::assertSame([], $link->executed, 'a lifetime beyond the portable maximum must never reach the database.');
    }

    public function test_an_update_that_matches_a_row_never_touches_insert(): void
    {
        $link = new ScriptedSqlLink([
            new FakeSqlRowResult(rowCount: 1),
        ]);

        new SqlSessionStore($link)->write('sid-1', ['user' => 42], 3600);

        self::assertCount(1, $link->executed, 'A matched UPDATE must be the only statement issued.');
        self::assertSame(self::UPDATE_SQL, $link->executed[0][0]);
    }

    public function test_an_ordinary_insert_succeeds_when_no_row_existed(): void
    {
        $link = new ScriptedSqlLink([
            new FakeSqlRowResult(rowCount: 0),
            new FakeSqlRowResult(rowCount: 1),
        ]);

        new SqlSessionStore($link)->write('sid-2', ['user' => 42], 3600);

        self::assertCount(2, $link->executed, 'A brand-new row must never trigger the retry/verify path.');
        self::assertSame(self::UPDATE_SQL, $link->executed[0][0]);
        self::assertSame(self::INSERT_SQL, $link->executed[1][0]);
    }

    /**
     * The scenario the class docblock names directly: MySQL reports 0
     * affected rows for a byte-identical UPDATE, so the retry can't prove
     * anything on its own — but the row genuinely exists with the exact
     * intended payload/expiry, and the existence check proves it.
     */
    public function test_a_byte_identical_retry_with_a_matching_row_recovers(): void
    {
        $duplicateKeyFailure = new QueryException('Duplicate entry for key PRIMARY', self::INSERT_SQL);
        $link = new ScriptedSqlLink([
            new FakeSqlRowResult(rowCount: 0),
            $duplicateKeyFailure,
            new FakeSqlRowResult(rowCount: 0),
            new FakeSqlRowResult(row: ['id' => 'sid-3']),
        ]);

        new SqlSessionStore($link)->write('sid-3', ['user' => 42], 3600);

        self::assertCount(4, $link->executed);
        self::assertSame(self::VERIFY_SQL, $link->executed[3][0]);

        // The verify query must carry exactly the values write() itself
        // just tried to persist — not something else — proven by
        // cross-checking against the earlier UPDATE call's own params
        // rather than predicting write()'s internal time()-derived
        // timestamp.
        [, $updateParams] = $link->executed[0];
        [, $verifyParams] = $link->executed[3];
        self::assertSame(['sid-3', $updateParams[0], $updateParams[1]], $verifyParams);
    }

    /**
     * A genuine concurrent race: the other request's INSERT won, and
     * this retry's own UPDATE visibly changed the row it left behind —
     * a real, driver-reported nonzero count needs no further proof.
     */
    public function test_a_successful_retry_recovers_without_needing_verification(): void
    {
        $link = new ScriptedSqlLink([
            new FakeSqlRowResult(rowCount: 0),
            new QueryException('Duplicate entry for key PRIMARY', self::INSERT_SQL),
            new FakeSqlRowResult(rowCount: 1),
        ]);

        new SqlSessionStore($link)->write('sid-4', ['user' => 42], 3600);

        self::assertCount(3, $link->executed, 'A retry that visibly updated a row must skip the verify query entirely.');
    }

    /**
     * The INSERT's own QueryException is not proof of a duplicate-key
     * race — it's equally what a connection failure, a constraint
     * violation, or a permissions error looks like. A retry that
     * changes nothing (0) and an existence check that finds no matching
     * row means the row was never actually written; the original,
     * genuine insert failure must be what the caller sees.
     */
    public function test_a_non_duplicate_insert_failure_with_no_matching_row_rethrows_the_original(): void
    {
        $connectionFailure = new QueryException('Connection reset by peer', self::INSERT_SQL);
        $link = new ScriptedSqlLink([
            new FakeSqlRowResult(rowCount: 0),
            $connectionFailure,
            new FakeSqlRowResult(rowCount: 0),
            new FakeSqlRowResult(row: null),
        ]);

        try {
            new SqlSessionStore($link)->write('sid-5', ['user' => 42], 3600);
            self::fail('Expected the original QueryException to propagate.');
        } catch (QueryException $e) {
            self::assertSame($connectionFailure, $e, 'The exact original failure must propagate, not a new or generic one.');
        }
    }

    /**
     * The same rethrow, but through the null-row-count branch — a
     * driver that can't report affected rows at all is exactly as
     * inconclusive as reporting 0, and must be treated identically:
     * proof requires the verify query, not the retry's own count.
     */
    public function test_a_null_retry_row_count_with_no_matching_row_also_rethrows_the_original(): void
    {
        $insertFailure = new QueryException('constraint violation', self::INSERT_SQL);
        $link = new ScriptedSqlLink([
            new FakeSqlRowResult(rowCount: 0),
            $insertFailure,
            new FakeSqlRowResult(rowCount: null),
            new FakeSqlRowResult(row: null),
        ]);

        try {
            new SqlSessionStore($link)->write('sid-6', ['user' => 42], 3600);
            self::fail('Expected the original QueryException to propagate.');
        } catch (QueryException $e) {
            self::assertSame($insertFailure, $e);
        }
    }

    /**
     * A failure while verifying is a third, genuinely new error — never
     * silently treated as success, and never relabeled as the original
     * insert failure either. Left uncaught deliberately: it must
     * propagate as itself.
     */
    public function test_a_failure_during_verification_propagates_as_itself(): void
    {
        $insertFailure = new QueryException('constraint violation', self::INSERT_SQL);
        $verificationFailure = new QueryException('server has gone away', self::VERIFY_SQL);
        $link = new ScriptedSqlLink([
            new FakeSqlRowResult(rowCount: 0),
            $insertFailure,
            new FakeSqlRowResult(rowCount: 0),
            $verificationFailure,
        ]);

        try {
            new SqlSessionStore($link)->write('sid-7', ['user' => 42], 3600);
            self::fail('Expected the verification failure to propagate.');
        } catch (QueryException $e) {
            self::assertSame($verificationFailure, $e);
            self::assertNotSame($insertFailure, $e, 'A verification-time error must not be relabeled as the original insert failure.');
        }
    }
}
