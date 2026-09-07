<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests\Store;

use Kinetis\Session\Exception\SessionException;
use Kinetis\Session\Store\SqlSessionStore;
use Kinetis\Session\Tests\Fixtures\FakeSqlRowResult;
use Kinetis\Session\Tests\Fixtures\ScriptedSqlLink;
use PHPUnit\Framework\TestCase;

/**
 * The SQL text and statement sequencing, against a scripted fake. How a
 * real server counts affected rows is what SqlSessionStoreIntegrationTest
 * exists to prove instead; a fake can't simulate that authentically.
 */
final class SqlSessionStoreTest extends TestCase
{
    private const string UPDATE_SQL =
        'UPDATE kinetis_sessions SET payload = ?, expires_at = ? WHERE id = ? AND expires_at > ?';

    private const string EXISTS_SQL = 'SELECT id FROM kinetis_sessions WHERE id = ? AND expires_at > ?';

    /**
     * The shared expiry boundary, in the SQL text read() and gc() issue:
     * `expires_at > now` for a live read, `expires_at <= now` for gc()'s
     * deletion — the same boundary FileSessionStore applies in PHP.
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
     * The exact value create() binds for expires_at: a bare
     * `Y-m-d H:i:s` UTC literal with no timezone marker of any kind (no
     * 'Z', no offset, no ISO-8601 'T'), which is what makes it safe
     * against a timezone-naive column. A tolerant clock-bracketed
     * window rather than exact equality — create() reads the clock
     * itself; string comparison is valid because `Y-m-d H:i:s` sorts
     * chronologically.
     */
    public function test_create_binds_a_bare_utc_wall_clock_string_with_no_timezone_marker(): void
    {
        $link = new ScriptedSqlLink([new FakeSqlRowResult(rowCount: 1)]);

        $before = \gmdate('Y-m-d H:i:s', \time() + 3600);
        new SqlSessionStore($link)->create('sid-format', ['user' => 42], 3600);
        $after = \gmdate('Y-m-d H:i:s', \time() + 3600);

        [, $params] = $link->executed[0];
        $expiresAt = $params[2];

        self::assertIsString($expiresAt);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $expiresAt);
        self::assertGreaterThanOrEqual($before, $expiresAt);
        self::assertLessThanOrEqual($after, $expiresAt);
    }

    /**
     * A non-positive lifetime is rejected before any SQL statement is
     * issued: the scripted link is empty, so reaching execute() at all
     * throws its own RuntimeException.
     */
    public function test_a_non_positive_lifetime_is_rejected_before_touching_the_database(): void
    {
        $link = new ScriptedSqlLink([]);

        foreach ([0, -1] as $lifetime) {
            try {
                new SqlSessionStore($link)->create('sid-invalid', ['user' => 42], $lifetime);
                self::fail("Expected SessionException for lifetime {$lifetime}.");
            } catch (SessionException $e) {
                self::assertStringContainsString('Session lifetime must be a positive number of seconds', $e->getMessage());
            }
        }

        self::assertSame([], $link->executed, 'an invalid lifetime must never reach the database.');
    }

    public function test_create_is_one_plain_insert(): void
    {
        $link = new ScriptedSqlLink([new FakeSqlRowResult(rowCount: 1)]);

        new SqlSessionStore($link)->create('sid-1', ['user' => 42], 3600);

        self::assertCount(1, $link->executed);
        self::assertSame('INSERT INTO kinetis_sessions (id, payload, expires_at) VALUES (?, ?, ?)', $link->executed[0][0]);
    }

    public function test_an_update_that_changed_a_row_needs_no_further_statement(): void
    {
        $link = new ScriptedSqlLink([new FakeSqlRowResult(rowCount: 1)]);

        self::assertTrue(new SqlSessionStore($link)->update('sid-2', ['user' => 42], 3600));

        self::assertCount(1, $link->executed, 'a reported change is proof enough on its own.');
        self::assertSame(self::UPDATE_SQL, $link->executed[0][0]);
    }

    /**
     * MySQL counts changed rows, so a byte-identical UPDATE reports 0
     * against a row that is still there. The existence check is what
     * separates that from a record another request removed.
     */
    public function test_a_zero_row_update_with_a_live_row_present_reports_the_record(): void
    {
        $link = new ScriptedSqlLink([
            new FakeSqlRowResult(rowCount: 0),
            new FakeSqlRowResult(row: ['id' => 'sid-3']),
        ]);

        self::assertTrue(new SqlSessionStore($link)->update('sid-3', ['user' => 42], 3600));

        self::assertCount(2, $link->executed);
        self::assertSame(self::EXISTS_SQL, $link->executed[1][0]);
    }

    public function test_a_zero_row_update_with_no_live_row_reports_a_stale_write(): void
    {
        $link = new ScriptedSqlLink([
            new FakeSqlRowResult(rowCount: 0),
            new FakeSqlRowResult(row: null),
        ]);

        self::assertFalse(new SqlSessionStore($link)->update('sid-4', ['user' => 42], 3600));
    }

    /**
     * A driver that cannot count affected rows at all is exactly as
     * inconclusive as one reporting 0, and takes the same check.
     */
    public function test_a_null_row_count_is_settled_by_the_same_existence_check(): void
    {
        $link = new ScriptedSqlLink([
            new FakeSqlRowResult(rowCount: null),
            new FakeSqlRowResult(row: ['id' => 'sid-5']),
        ]);

        self::assertTrue(new SqlSessionStore($link)->update('sid-5', ['user' => 42], 3600));
        self::assertCount(2, $link->executed);
    }
}
