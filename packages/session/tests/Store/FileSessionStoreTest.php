<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests\Store;

use Kinetis\Session\Exception\SessionException;
use Kinetis\Session\Store\FileSessionStore;
use Kinetis\Session\Tests\Fixtures\FailingChmodStreamWrapper;
use Kinetis\Session\Tests\Fixtures\FailingWriteStreamWrapper;
use Kinetis\Session\Tests\Fixtures\RecordingStreamWrapper;
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
        $this->store->create($id, ['user' => 42, 'nested' => ['a' => true]], 60);

        self::assertSame(['user' => 42, 'nested' => ['a' => true]], $this->store->read($id));
    }

    /**
     * A freshly created session directory's real, resulting mode — not
     * merely "construction didn't throw" — must be private.
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
        $store->create($id, ['x' => 1], 60);

        self::assertSame(['x' => 1], $store->read($id));

        \unlink($directory . '/sess_' . $id);
        \rmdir($directory);
    }

    /**
     * An externally-provisioned, group- or world-accessible directory is
     * refused outright, not silently narrowed: this store does not own a
     * directory it did not create, and correcting its permissions could
     * mask a deployment mistake or disturb something else relying on
     * that mode.
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
     * A written session file's real, resulting mode — not merely
     * "create() didn't throw" — must be private.
     */
    public function test_a_written_session_file_gets_the_private_mode(): void
    {
        $id = self::id();
        $this->store->create($id, ['x' => 1], 60);

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
     * A session whose expiresAt is exactly the current second is
     * already expired, matching SqlSessionStore's `expires_at > now` /
     * `expires_at <= now` boundary. Seeded directly, since
     * writeExpiredFile() always seeds one second further back.
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

    /** A non-positive lifetime is rejected before any file is touched. */
    public function test_create_rejects_a_non_positive_lifetime(): void
    {
        foreach ([0, -1] as $lifetime) {
            try {
                $this->store->create(self::id(), ['x' => 1], $lifetime);
                self::fail("Expected SessionException for lifetime {$lifetime}.");
            } catch (SessionException $e) {
                self::assertStringContainsString('Session lifetime must be a positive number of seconds', $e->getMessage());
            }
        }

        self::assertSame([], \glob($this->directory . '/sess_*') ?: [], 'no file must ever be written for a rejected lifetime.');
        $this->assertNoStrayTempFiles();
    }

    public function test_destroy_removes_the_file(): void
    {
        $id = self::id();
        $this->store->create($id, ['x' => 1], 60);
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
        // interest in), so going through it here would fail at create()
        // rather than at the destroy() this test actually exercises.
        $id = self::id();
        $this->store->create($id, ['x' => 1], 60);

        FailingWriteStreamWrapper::$backingDirectory = $this->directory;
        \stream_wrapper_register(FailingWriteStreamWrapper::SCHEME, FailingWriteStreamWrapper::class);

        try {
            $wrappedStore = new FileSessionStore(FailingWriteStreamWrapper::SCHEME . '://');
            FailingWriteStreamWrapper::$failUnlink = true;

            $this->expectException(SessionException::class);
            $this->expectExceptionMessage('A session file could not be deleted.');

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
        $this->store->create($live, ['keep' => true], 60);
        $this->writeExpiredFile($dead, ['gone' => true]);

        self::assertSame(1, $this->store->gc());
        self::assertSame(['keep' => true], $this->store->read($live));
        self::assertCount(1, \glob($this->directory . '/sess_*') ?: []);
    }

    /**
     * create() rejects a non-positive $lifetimeSeconds, so an
     * already-expired file is seeded directly, in the envelope shape
     * create() produces.
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
     * The temp file create() creates between file_put_contents() and
     * rename() must never be named so that gc()'s own glob("sess_*")
     * could match it — a naive "$path.<random>.tmp" naming (starting
     * with "sess_") would let a concurrent gc() sweep collect and unlink
     * a write still in progress, making the rename() below it fail and
     * silently losing the update. RecordingStreamWrapper observes the
     * exact path create() passes to file_put_contents() — the real
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
            $store->create(self::id(), ['x' => 1], 60);

            // create() itself makes two writes: the temp file, then the
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
     * file_put_contents() can create a file and write some bytes to it
     * before failing — running out of disk space mid-write is its own
     * documented example. A partial temp file must never be left behind.
     * FailingWriteStreamWrapper reproduces that deterministically: a
     * partial file lands on the real backing directory before
     * file_put_contents() reports failure.
     */
    public function test_create_cleans_up_a_partially_written_temp_file_when_file_put_contents_fails(): void
    {
        FailingWriteStreamWrapper::$backingDirectory = $this->directory;
        \stream_wrapper_register(FailingWriteStreamWrapper::SCHEME, FailingWriteStreamWrapper::class);

        try {
            $store = new FileSessionStore(FailingWriteStreamWrapper::SCHEME . '://');

            try {
                $store->create(self::id(), ['x' => 1], 60);
                self::fail('Expected SessionException.');
            } catch (SessionException) {
                // Expected — file_put_contents() was made to fail.
            }

            $stray = \array_values(\array_filter(
                \scandir($this->directory) ?: [],
                static fn (string $file): bool => \str_ends_with($file, '.tmp'),
            ));

            self::assertSame([], $stray, 'A partially-written temp file was left behind after a failed create().');
        } finally {
            \stream_wrapper_unregister(FailingWriteStreamWrapper::SCHEME);
        }
    }

    /**
     * A shorter replacement, so the truncate that follows the write has
     * to remove the old tail — a leftover would make the envelope
     * undecodable.
     */
    public function test_update_replaces_a_live_record(): void
    {
        $id = self::id();
        $this->store->create($id, ['step' => 1, 'padding' => \str_repeat('x', 4096)], 60);

        self::assertTrue($this->store->update($id, ['step' => 2], 60));
        self::assertSame(['step' => 2], $this->store->read($id));
    }

    /**
     * The terminal rule, and the reason update() opens the file rather
     * than publishing a new one by rename: a record another request
     * removed must stay removed.
     */
    public function test_update_refuses_a_destroyed_record_and_creates_no_file(): void
    {
        $id = self::id();
        $this->store->create($id, ['user' => 42], 60);
        $this->store->destroy($id);

        self::assertFalse($this->store->update($id, ['user' => 42], 60));
        self::assertFileDoesNotExist($this->directory . '/sess_' . $id);
    }

    public function test_update_refuses_an_expired_record(): void
    {
        $id = self::id();
        $this->writeExpiredFile($id, ['user' => 42]);

        self::assertFalse($this->store->update($id, ['user' => 43], 60));
    }

    /**
     * An update writes in place, so a read overlapping it can land on a
     * partial envelope. That reads as an absent session rather than as
     * half-applied data — the fail-closed trade update() makes for the
     * terminal rule.
     */
    public function test_a_partially_written_record_reads_as_absent(): void
    {
        $id = self::id();
        $this->store->create($id, ['user' => 42], 60);
        \file_put_contents($this->directory . '/sess_' . $id, '{"expiresAt":');

        self::assertNull($this->store->read($id));
    }

    /** A chmod() call that reports failure must fail the write, with cleanup. */
    public function test_create_throws_and_cleans_up_when_chmod_itself_fails(): void
    {
        FailingChmodStreamWrapper::$backingDirectory = $this->directory;
        \stream_wrapper_register(FailingChmodStreamWrapper::SCHEME, FailingChmodStreamWrapper::class);

        try {
            $store = new FileSessionStore(FailingChmodStreamWrapper::SCHEME . '://');
            FailingChmodStreamWrapper::$failChmodCall = true;

            $this->expectException(SessionException::class);
            $this->expectExceptionMessage('could not be secured with private permissions');

            try {
                $store->create(self::id(), ['x' => 1], 60);
            } finally {
                $this->assertNoStrayTempFiles();
            }
        } finally {
            FailingChmodStreamWrapper::$failChmodCall = false;
            \stream_wrapper_unregister(FailingChmodStreamWrapper::SCHEME);
        }
    }

    /**
     * A chmod() call that reports success without the file's real,
     * resulting mode being private must fail the write, with cleanup,
     * rather than publish a session file wider than intended.
     */
    public function test_create_throws_and_cleans_up_when_the_resulting_mode_does_not_match(): void
    {
        FailingChmodStreamWrapper::$backingDirectory = $this->directory;
        \stream_wrapper_register(FailingChmodStreamWrapper::SCHEME, FailingChmodStreamWrapper::class);

        try {
            $store = new FileSessionStore(FailingChmodStreamWrapper::SCHEME . '://');
            FailingChmodStreamWrapper::$reportedModeAfterChmod = 0644;

            $this->expectException(SessionException::class);
            $this->expectExceptionMessage('could not be secured with private permissions');

            try {
                $store->create(self::id(), ['x' => 1], 60);
            } finally {
                $this->assertNoStrayTempFiles();
            }
        } finally {
            FailingChmodStreamWrapper::$reportedModeAfterChmod = null;
            \stream_wrapper_unregister(FailingChmodStreamWrapper::SCHEME);
        }
    }

    /** A stat() failure after a "successful" chmod() counts the same as a mismatch. */
    public function test_create_throws_and_cleans_up_when_stat_fails_after_chmod(): void
    {
        FailingChmodStreamWrapper::$backingDirectory = $this->directory;
        \stream_wrapper_register(FailingChmodStreamWrapper::SCHEME, FailingChmodStreamWrapper::class);

        try {
            $store = new FileSessionStore(FailingChmodStreamWrapper::SCHEME . '://');
            FailingChmodStreamWrapper::$failStatAfterChmod = true;

            $this->expectException(SessionException::class);
            $this->expectExceptionMessage('could not be secured with private permissions');

            try {
                $store->create(self::id(), ['x' => 1], 60);
            } finally {
                $this->assertNoStrayTempFiles();
            }
        } finally {
            FailingChmodStreamWrapper::$failStatAfterChmod = false;
            \stream_wrapper_unregister(FailingChmodStreamWrapper::SCHEME);
        }
    }

    /**
     * A failed replacement must never lose the previous, still-live
     * session: the failing create() throws before rename(), so the
     * original file is untouched, read back here through the real,
     * unwrapped store.
     */
    public function test_a_failed_write_due_to_a_bad_chmod_leaves_the_previous_live_session_intact(): void
    {
        $id = self::id();
        $this->store->create($id, ['original' => true], 60);

        FailingChmodStreamWrapper::$backingDirectory = $this->directory;
        \stream_wrapper_register(FailingChmodStreamWrapper::SCHEME, FailingChmodStreamWrapper::class);

        try {
            $wrappedStore = new FileSessionStore(FailingChmodStreamWrapper::SCHEME . '://');
            FailingChmodStreamWrapper::$failChmodCall = true;

            try {
                $wrappedStore->create($id, ['replacement' => true], 60);
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

        self::assertSame([], $stray, 'A temp file was left behind after a failed create().');
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
        $this->store->create($id, ['value' => \str_repeat('A', 200_000)], 3600);

        $writerScript = $this->directory . '/writer.php';
        \file_put_contents($writerScript, <<<PHP
            <?php
            require '{$bootstrap}';
            \$store = new Kinetis\Session\Store\FileSessionStore('{$this->directory}');
            for (\$i = 0; \$i < 400; \$i++) {
                \$store->create('{$id}', ['value' => str_repeat(\$i % 2 === 0 ? 'A' : 'B', 200_000)], 3600);
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
