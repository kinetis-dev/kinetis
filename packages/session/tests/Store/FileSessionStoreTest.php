<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests\Store;

use Kinetis\Session\Exception\SessionException;
use Kinetis\Session\Store\FileSessionStore;
use Kinetis\Session\Tests\Fixtures\FailingChmodStreamWrapper;
use Kinetis\Session\Tests\Fixtures\FailingWriteStreamWrapper;
use Kinetis\Session\Tests\Fixtures\RecordingStreamWrapper;
use Kinetis\Session\Support\SessionExpiry;
use PHPUnit\Framework\TestCase;

final class FileSessionStoreTest extends TestCase
{
    private string $directory;

    private FileSessionStore $store;

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = \sys_get_temp_dir() . '/kinetis-session-test-' . \bin2hex(\random_bytes(6));
        $this->store = new FileSessionStore($this->directory);
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (\glob($this->directory . '/*') ?: [] as $file) {
            @\unlink($file);
        }

        @\rmdir($this->directory);
    }

    private static function id(): string
    {
        return \bin2hex(\random_bytes(16));
    }

    public function test_round_trip(): void
    {
        $id = self::id();
        $this->store->write($id, ['user' => 42, 'nested' => ['a' => true]], 60);

        self::assertSame(['user' => 42, 'nested' => ['a' => true]], $this->store->read($id));
    }

    /**
     * KINETIS-66: a freshly created session directory's real, resulting
     * mode — not merely "construction didn't throw" — must actually be
     * private.
     */
    public function test_a_freshly_created_directory_gets_the_private_mode(): void
    {
        $mode = \fileperms($this->directory);

        self::assertNotFalse($mode);
        self::assertSame(0700, $mode & 0777);
    }

    /**
     * A pre-existing directory that already happens to be private is
     * accepted without complaint — the check is about safety, not about
     * who created the directory.
     */
    public function test_a_pre_existing_private_directory_is_accepted(): void
    {
        $directory = \sys_get_temp_dir() . '/kinetis-session-test-' . \bin2hex(\random_bytes(6));
        \mkdir($directory);
        \chmod($directory, 0700);

        $store = new FileSessionStore($directory);
        $id = self::id();
        $store->write($id, ['x' => 1], 60);

        self::assertSame(['x' => 1], $store->read($id));

        \unlink($directory . '/sess_' . $id);
        \rmdir($directory);
    }

    /**
     * KINETIS-66: an externally-provisioned, group- or world-accessible
     * directory is refused outright, not silently narrowed — this store
     * does not own a directory it did not create, and correcting its
     * permissions on its behalf could mask a real deployment mistake or
     * step on something else relying on that directory's current mode.
     */
    public function test_a_pre_existing_group_or_world_accessible_directory_is_refused(): void
    {
        $directory = \sys_get_temp_dir() . '/kinetis-session-test-' . \bin2hex(\random_bytes(6));
        \mkdir($directory);
        \chmod($directory, 0755);

        try {
            $this->expectException(SessionException::class);
            $this->expectExceptionMessage('is group- or world-accessible');

            new FileSessionStore($directory);
        } finally {
            \rmdir($directory);
        }
    }

    /**
     * KINETIS-66: a written session file's real, resulting mode — not
     * merely "write() didn't throw" — must actually be private.
     */
    public function test_a_written_session_file_gets_the_private_mode(): void
    {
        $id = self::id();
        $this->store->write($id, ['x' => 1], 60);

        $mode = \fileperms($this->directory . '/sess_' . $id);

        self::assertNotFalse($mode);
        self::assertSame(0600, $mode & 0777);
    }

    public function test_unknown_id_reads_null(): void
    {
        self::assertNull($this->store->read(self::id()));
    }

    public function test_an_expired_session_reads_null_and_its_file_is_removed(): void
    {
        $id = self::id();
        $this->writeExpiredFile($id, ['x' => 1]);

        self::assertNull($this->store->read($id));
        self::assertSame([], \glob($this->directory . '/sess_*') ?: []);
    }

    /**
     * KINETIS-68: a session whose expiresAt is exactly the current
     * second — not one second short of it — must already be treated as
     * expired, matching SqlSessionStore's own `expires_at > now` /
     * `expires_at <= now` boundary exactly. Seeded directly with
     * expiresAt === time() (writeExpiredFile() itself always seeds one
     * second in the past, which is a stronger, less precise case than
     * this exact-boundary one).
     */
    public function test_a_session_expiring_exactly_now_reads_null_and_is_removed(): void
    {
        $id = self::id();
        \file_put_contents(
            $this->directory . '/sess_' . $id,
            \json_encode(['expiresAt' => \time(), 'data' => ['x' => 1]], JSON_THROW_ON_ERROR),
        );

        self::assertNull($this->store->read($id));
        self::assertSame([], \glob($this->directory . '/sess_*') ?: []);
    }

    /**
     * KINETIS-68 FEEDBACK 2: the other side of the same boundary — a
     * session with a healthy future expiry must still read as live.
     * Deliberately a safe margin, not the razor's-edge +1 second this
     * test originally used: read() has no injectable clock (its
     * signature is fixed by SessionStoreInterface), so seeding
     * expiresAt from one time() call here and letting read() make its
     * own separate one is a real race a slow or preempted process could
     * lose — exactly what the prior feedback round flagged. The exact
     * one-second boundary is proven deterministically instead by
     * SessionExpiryTest's own isExpired() tests, which take both sides
     * of the comparison as fixed arguments and never touch the real
     * clock at all.
     */
    public function test_a_session_expiring_well_into_the_future_still_reads_live(): void
    {
        $id = self::id();
        \file_put_contents(
            $this->directory . '/sess_' . $id,
            \json_encode(['expiresAt' => \time() + 3600, 'data' => ['x' => 1]], JSON_THROW_ON_ERROR),
        );

        self::assertSame(['x' => 1], $this->store->read($id));
    }

    /**
     * KINETIS-68: a non-positive lifetime must be rejected before any
     * file is ever touched, on both the "invalid" and "already covered
     * by the exception message" fronts — proven for both 0 and a
     * negative value, the two distinct rejected shapes.
     */
    public function test_write_rejects_a_non_positive_lifetime(): void
    {
        foreach ([0, -1] as $lifetime) {
            try {
                $this->store->write(self::id(), ['x' => 1], $lifetime);
                self::fail("Expected SessionException for lifetime {$lifetime}.");
            } catch (SessionException $e) {
                self::assertStringContainsString('Session lifetime must be a positive number of seconds', $e->getMessage());
            }
        }

        self::assertSame([], \glob($this->directory . '/sess_*') ?: [], 'no file must ever be written for a rejected lifetime.');
        $this->assertNoStrayTempFiles();
    }

    /**
     * KINETIS-68: time() + PHP_INT_MAX overflows to a float — write()
     * must reject this before ever encoding/writing anything, rather
     * than publishing a file its own reader would immediately reject as
     * malformed.
     */
    public function test_write_rejects_an_overflowing_lifetime(): void
    {
        $id = self::id();

        $this->expectException(SessionException::class);
        $this->expectExceptionMessage('produces an expiry beyond');

        try {
            $this->store->write($id, ['x' => 1], \PHP_INT_MAX);
        } finally {
            self::assertSame([], \glob($this->directory . '/sess_*') ?: []);
            $this->assertNoStrayTempFiles();
        }
    }

    /**
     * KINETIS-68 FEEDBACK: a lifetime that is a perfectly ordinary,
     * representable PHP int — no overflow involved — but still pushes
     * expiresAt past MAX_EXPIRES_AT must be rejected the same way,
     * before anything is written. Distinct from the overflow case above:
     * this proves the portable-maximum check itself, not just the
     * int-overflow guard.
     *
     * KINETIS-68 FEEDBACK 2: a safe 100-second margin past the maximum,
     * not the razor's-edge +1 this test originally used. write() has no
     * injectable clock (SessionStoreInterface fixes its signature), so
     * this test's own time() call and the one inside timestampFor() are
     * two genuinely separate clock reads a slow or preempted process
     * could let tick over between — a margin this wide survives any
     * realistic delay, while the exact one-second boundary is proven
     * deterministically by SessionExpiryTest's own timestampFor() tests,
     * which pin both sides to one hardcoded $now and never touch the
     * real clock at all.
     */
    public function test_write_rejects_a_lifetime_beyond_the_portable_maximum(): void
    {
        $id = self::id();
        $lifetime = SessionExpiry::MAX_EXPIRES_AT - \time() + 100;

        $this->expectException(SessionException::class);
        $this->expectExceptionMessage('produces an expiry beyond');

        try {
            $this->store->write($id, ['x' => 1], $lifetime);
        } finally {
            self::assertSame([], \glob($this->directory . '/sess_*') ?: []);
            $this->assertNoStrayTempFiles();
        }
    }

    /**
     * The other side of the same store-level boundary: a lifetime
     * comfortably under the portable maximum must still succeed.
     *
     * KINETIS-68 FEEDBACK 2: comfortably under, not landing exactly at
     * it — the exact boundary is a single arithmetic comparison
     * (SessionExpiry::isRepresentable()), already proven deterministically
     * by SessionExpiryTest against fixed, hand-picked values with zero
     * real-clock involvement. This test's own job is different: proving
     * a lifetime this large genuinely round-trips through the real
     * store's write()/read() path, which a two-separate-time()-calls
     * margin this wide can do safely without risking the flake an exact
     * boundary would.
     */
    public function test_write_accepts_a_lifetime_comfortably_under_the_portable_maximum(): void
    {
        $id = self::id();
        $lifetime = SessionExpiry::MAX_EXPIRES_AT - \time() - 100;

        $this->store->write($id, ['x' => 1], $lifetime);

        self::assertSame(['x' => 1], $this->store->read($id));
    }

    public function test_destroy_removes_the_file(): void
    {
        $id = self::id();
        $this->store->write($id, ['x' => 1], 60);
        $this->store->destroy($id);

        self::assertNull($this->store->read($id));
    }

    /**
     * unlink() failing on an id that was never written (or already
     * destroyed by a concurrent request/gc() sweep) is the ordinary,
     * benign case — destroy() must treat it as a no-op, not an error.
     */
    public function test_destroy_on_a_nonexistent_id_is_a_silent_no_op(): void
    {
        $this->store->destroy(self::id());

        self::assertTrue(true, 'destroy() on a never-written id must not throw.');
    }

    /**
     * unlink() failing while the file genuinely still exists afterward —
     * a real I/O/permission failure, not the benign already-gone case —
     * must be a SessionException, not a silently-ignored @unlink().
     * FailingWriteStreamWrapper::$failUnlink is the deterministic,
     * injectable seam for this: it reports failure without touching the
     * real file, so the "still exists" branch is genuinely exercised
     * rather than relying on environment-specific permission tricks.
     */
    public function test_destroy_throws_when_unlink_fails_but_the_file_still_exists(): void
    {
        // Written through the real, unwrapped store first — the wrapper's
        // own stream_write() always simulates a mid-write failure after
        // its first chunk (a separate fixture behavior this test has no
        // interest in), so going through it here would fail at write()
        // rather than at the destroy() this test actually exercises.
        $id = self::id();
        $this->store->write($id, ['x' => 1], 60);

        FailingWriteStreamWrapper::$backingDirectory = $this->directory;
        \stream_wrapper_register(FailingWriteStreamWrapper::SCHEME, FailingWriteStreamWrapper::class);

        try {
            $wrappedStore = new FileSessionStore(FailingWriteStreamWrapper::SCHEME . '://');
            FailingWriteStreamWrapper::$failUnlink = true;

            $this->expectException(SessionException::class);
            $this->expectExceptionMessage("Session file for \"{$id}\" could not be deleted.");

            $wrappedStore->destroy($id);
        } finally {
            FailingWriteStreamWrapper::$failUnlink = false;
            \stream_wrapper_unregister(FailingWriteStreamWrapper::SCHEME);
        }
    }

    public function test_gc_sweeps_expired_files_keeps_live_ones_and_reports_the_count(): void
    {
        $live = self::id();
        $dead = self::id();
        $this->store->write($live, ['keep' => true], 60);
        $this->writeExpiredFile($dead, ['gone' => true]);

        self::assertSame(1, $this->store->gc());
        self::assertSame(['keep' => true], $this->store->read($live));
        self::assertCount(1, \glob($this->directory . '/sess_*') ?: []);
    }

    /**
     * write() itself now rejects a non-positive $lifetimeSeconds
     * (KINETIS-68), so an already-expired file for a test to observe is
     * seeded directly, in the exact real envelope shape write() itself
     * produces — the same technique the corrupt-file test already uses
     * for writing a raw file outside write()'s own contract.
     *
     * @param array<string, mixed> $data
     */
    private function writeExpiredFile(string $id, array $data): void
    {
        \file_put_contents(
            $this->directory . '/sess_' . $id,
            \json_encode(['expiresAt' => \time() - 1, 'data' => $data], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * The temp file write() creates between file_put_contents() and
     * rename() must never be named so that gc()'s own glob("sess_*")
     * could match it — a naive "$path.<random>.tmp" naming (starting
     * with "sess_") would let a concurrent gc() sweep collect and unlink
     * a write still in progress, making the rename() below it fail and
     * silently losing the update. RecordingStreamWrapper observes the
     * exact path write() passes to file_put_contents() — the real
     * naming logic, not a hardcoded guess — so this is a direct,
     * deterministic proof, not a timing-dependent live race (which
     * would need to land inside a window narrow enough that it can't be
     * relied on to fail reliably against the pre-fix naming either).
     */
    public function test_writes_temp_file_is_never_named_so_gc_could_collect_it(): void
    {
        RecordingStreamWrapper::$backingDirectory = $this->directory;
        RecordingStreamWrapper::$writtenPaths = [];
        \stream_wrapper_register(RecordingStreamWrapper::SCHEME, RecordingStreamWrapper::class);

        try {
            $store = new FileSessionStore(RecordingStreamWrapper::SCHEME . '://');
            $store->write(self::id(), ['x' => 1], 60);

            // write() itself makes two writes: the temp file, then the
            // final rename target is untouched by stream_open() (rename()
            // is a separate wrapper method) — so exactly one write is
            // recorded, and it must be the temp file.
            self::assertCount(1, RecordingStreamWrapper::$writtenPaths);

            $basename = \basename(RecordingStreamWrapper::$writtenPaths[0]);

            self::assertFalse(
                \fnmatch('sess_*', $basename),
                "The temp file \"{$basename}\" matches gc()'s own \"sess_*\" glob pattern.",
            );
        } finally {
            \stream_wrapper_unregister(RecordingStreamWrapper::SCHEME);
        }
    }

    /**
     * file_put_contents() can genuinely create a file and write some
     * bytes to it before failing (its own documentation describes
     * exactly this — running out of disk space mid-write). The previous
     * code only ever unlinked the temp file in the rename()-failure
     * branch, leaving a partial temp file behind whenever
     * file_put_contents() itself was the one that failed.
     * FailingWriteStreamWrapper reproduces that exact scenario
     * deterministically — a genuinely partial file lands on the real
     * backing directory before file_put_contents() reports failure.
     *
     * KINETIS-66: this is also the one short-write shape actually
     * reachable from PHP userland. write() now compares
     * file_put_contents()'s return value against the exact expected byte
     * count rather than only "not false" — checked directly against
     * php-src (ext/standard/file.c): for a string payload,
     * file_put_contents() itself already converts *any* short/partial
     * write to false before returning, so a genuine positive-but-short
     * byte count cannot be produced here for this call to diverge on.
     * The exact-count comparison is kept as the objectively correct
     * check regardless — it does not depend on that implementation
     * detail remaining true — and this test confirms it still rejects
     * the one real short-write scenario identically to the old check.
     */
    public function test_write_cleans_up_a_partially_written_temp_file_when_file_put_contents_fails(): void
    {
        FailingWriteStreamWrapper::$backingDirectory = $this->directory;
        \stream_wrapper_register(FailingWriteStreamWrapper::SCHEME, FailingWriteStreamWrapper::class);

        try {
            $store = new FileSessionStore(FailingWriteStreamWrapper::SCHEME . '://');

            try {
                $store->write(self::id(), ['x' => 1], 60);
                self::fail('Expected SessionException.');
            } catch (SessionException) {
                // Expected — file_put_contents() was made to fail.
            }

            $stray = \array_values(\array_filter(
                \scandir($this->directory) ?: [],
                static fn (string $file): bool => \str_ends_with($file, '.tmp'),
            ));

            self::assertSame([], $stray, 'A partially-written temp file was left behind after a failed write().');
        } finally {
            \stream_wrapper_unregister(FailingWriteStreamWrapper::SCHEME);
        }
    }

    /** KINETIS-66: a chmod() call that itself reports failure must fail the write, with cleanup. */
    public function test_write_throws_and_cleans_up_when_chmod_itself_fails(): void
    {
        FailingChmodStreamWrapper::$backingDirectory = $this->directory;
        \stream_wrapper_register(FailingChmodStreamWrapper::SCHEME, FailingChmodStreamWrapper::class);

        try {
            $store = new FileSessionStore(FailingChmodStreamWrapper::SCHEME . '://');
            FailingChmodStreamWrapper::$failChmodCall = true;

            $this->expectException(SessionException::class);
            $this->expectExceptionMessage('could not be secured with private permissions');

            try {
                $store->write(self::id(), ['x' => 1], 60);
            } finally {
                $this->assertNoStrayTempFiles();
            }
        } finally {
            FailingChmodStreamWrapper::$failChmodCall = false;
            \stream_wrapper_unregister(FailingChmodStreamWrapper::SCHEME);
        }
    }

    /**
     * KINETIS-66: a chmod() call that reports success without the file's
     * real, resulting mode actually being private — the core "stat, not
     * just the return value" requirement — must fail the write, with
     * cleanup, rather than publish a session file wider than intended.
     */
    public function test_write_throws_and_cleans_up_when_the_resulting_mode_does_not_match(): void
    {
        FailingChmodStreamWrapper::$backingDirectory = $this->directory;
        \stream_wrapper_register(FailingChmodStreamWrapper::SCHEME, FailingChmodStreamWrapper::class);

        try {
            $store = new FileSessionStore(FailingChmodStreamWrapper::SCHEME . '://');
            FailingChmodStreamWrapper::$reportedModeAfterChmod = 0644;

            $this->expectException(SessionException::class);
            $this->expectExceptionMessage('could not be secured with private permissions');

            try {
                $store->write(self::id(), ['x' => 1], 60);
            } finally {
                $this->assertNoStrayTempFiles();
            }
        } finally {
            FailingChmodStreamWrapper::$reportedModeAfterChmod = null;
            \stream_wrapper_unregister(FailingChmodStreamWrapper::SCHEME);
        }
    }

    /** KINETIS-66: a stat() failure after a "successful" chmod() is treated the same as a real mismatch. */
    public function test_write_throws_and_cleans_up_when_stat_fails_after_chmod(): void
    {
        FailingChmodStreamWrapper::$backingDirectory = $this->directory;
        \stream_wrapper_register(FailingChmodStreamWrapper::SCHEME, FailingChmodStreamWrapper::class);

        try {
            $store = new FileSessionStore(FailingChmodStreamWrapper::SCHEME . '://');
            FailingChmodStreamWrapper::$failStatAfterChmod = true;

            $this->expectException(SessionException::class);
            $this->expectExceptionMessage('could not be secured with private permissions');

            try {
                $store->write(self::id(), ['x' => 1], 60);
            } finally {
                $this->assertNoStrayTempFiles();
            }
        } finally {
            FailingChmodStreamWrapper::$failStatAfterChmod = false;
            \stream_wrapper_unregister(FailingChmodStreamWrapper::SCHEME);
        }
    }

    /**
     * KINETIS-66's own explicit requirement: a failed replacement must
     * never lose the previous, still-live session — the failing write()
     * throws before ever calling rename(), so the original file it would
     * have replaced is provably untouched, read back here through the
     * real, unwrapped store to prove it.
     */
    public function test_a_failed_write_due_to_a_bad_chmod_leaves_the_previous_live_session_intact(): void
    {
        $id = self::id();
        $this->store->write($id, ['original' => true], 60);

        FailingChmodStreamWrapper::$backingDirectory = $this->directory;
        \stream_wrapper_register(FailingChmodStreamWrapper::SCHEME, FailingChmodStreamWrapper::class);

        try {
            $wrappedStore = new FileSessionStore(FailingChmodStreamWrapper::SCHEME . '://');
            FailingChmodStreamWrapper::$failChmodCall = true;

            try {
                $wrappedStore->write($id, ['replacement' => true], 60);
                self::fail('Expected SessionException.');
            } catch (SessionException) {
                // Expected.
            }
        } finally {
            FailingChmodStreamWrapper::$failChmodCall = false;
            \stream_wrapper_unregister(FailingChmodStreamWrapper::SCHEME);
        }

        self::assertSame(['original' => true], $this->store->read($id), 'a failed replacement write must never lose the previous, still-live session.');
    }

    private function assertNoStrayTempFiles(): void
    {
        $stray = \array_values(\array_filter(
            \scandir($this->directory) ?: [],
            static fn (string $file): bool => \str_ends_with($file, '.tmp'),
        ));

        self::assertSame([], $stray, 'A temp file was left behind after a failed write().');
    }

    public function test_a_malformed_id_never_becomes_a_path(): void
    {
        $this->expectException(SessionException::class);

        $this->store->read('../../etc/passwd');
    }

    public function test_a_corrupt_file_reads_null(): void
    {
        $id = self::id();
        \file_put_contents($this->directory . '/sess_' . $id, 'not json at all');

        self::assertNull($this->store->read($id));
    }

    /**
     * A real process-level race, not a single-process simulation: one
     * genuinely separate OS process (proc_open(), not a Fiber or
     * thread) repeatedly overwrites one session with a large payload
     * while another repeatedly reads the raw file bytes directly —
     * bypassing read()'s own graceful "invalid envelope -> null"
     * handling, which would otherwise silently swallow the exact
     * symptom this test exists to catch. A large payload widens the
     * write's own real duration, since a torn read needs a write still
     * in progress to actually be observable. Every observed byte
     * sequence must be one of the two complete, valid payloads —
     * anything else (invalid JSON, or valid JSON with a mixed 'A'/'B'
     * value) is a genuine torn read.
     */
    public function test_concurrent_writes_and_reads_never_produce_a_torn_file(): void
    {
        $id = self::id();
        $path = $this->directory . '/sess_' . $id;
        $bootstrap = __DIR__ . '/../../vendor/autoload.php';

        // Seeded synchronously, before either racing process starts —
        // otherwise an empty/missing read during the race's own opening
        // moment is ambiguous between "genuinely torn" and "the first
        // write just hasn't happened yet", which would make the reader
        // have to ignore exactly the symptom (a truncated-to-empty file)
        // this test exists to catch.
        $this->store->write($id, ['value' => \str_repeat('A', 200_000)], 3600);

        $writerScript = $this->directory . '/writer.php';
        \file_put_contents($writerScript, <<<PHP
            <?php
            require '{$bootstrap}';
            \$store = new Kinetis\Session\Store\FileSessionStore('{$this->directory}');
            for (\$i = 0; \$i < 400; \$i++) {
                \$store->write('{$id}', ['value' => str_repeat(\$i % 2 === 0 ? 'A' : 'B', 200_000)], 3600);
            }
            PHP);

        $readerScript = $this->directory . '/reader.php';
        \file_put_contents($readerScript, <<<PHP
            <?php
            \$violations = 0;
            for (\$i = 0; \$i < 2000; \$i++) {
                \$raw = @file_get_contents('{$path}');
                \$envelope = \$raw === false ? null : json_decode(\$raw, true);
                \$value = \$envelope['data']['value'] ?? null;
                \$ok = is_string(\$value)
                    && strlen(\$value) === 200_000
                    && (\$value === str_repeat('A', 200_000) || \$value === str_repeat('B', 200_000));
                if (!\$ok) {
                    \$violations++;
                }
            }
            echo \$violations;
            PHP);

        $writer = \proc_open(['php', $writerScript], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $writerPipes);
        $reader = \proc_open(['php', $readerScript], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $readerPipes);

        self::assertIsResource($writer);
        self::assertIsResource($reader);

        $readerOutput = \stream_get_contents($readerPipes[1]);
        $readerError = \stream_get_contents($readerPipes[2]);
        \fclose($readerPipes[1]);
        \fclose($readerPipes[2]);
        \proc_close($reader);

        \fclose($writerPipes[1]);
        \fclose($writerPipes[2]);
        \proc_close($writer);

        \unlink($writerScript);
        \unlink($readerScript);

        self::assertSame('', $readerError, 'Reader script produced unexpected error output.');
        self::assertSame('0', $readerOutput, 'A concurrent read observed a torn (partial/mixed) session file.');
    }
}
