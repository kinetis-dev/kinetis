<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests\Store;

use Kinetis\Session\Exception\SessionException;
use Kinetis\Session\Store\FileSessionStore;
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
        $this->store->write($id, ['user' => 42, 'nested' => ['a' => true]], 60);

        self::assertSame(['user' => 42, 'nested' => ['a' => true]], $this->store->read($id));
    }

    public function test_unknown_id_reads_null(): void
    {
        self::assertNull($this->store->read(self::id()));
    }

    public function test_an_expired_session_reads_null_and_its_file_is_removed(): void
    {
        $id = self::id();
        $this->store->write($id, ['x' => 1], -1);

        self::assertNull($this->store->read($id));
        self::assertSame([], \glob($this->directory . '/sess_*') ?: []);
    }

    public function test_destroy_removes_the_file(): void
    {
        $id = self::id();
        $this->store->write($id, ['x' => 1], 60);
        $this->store->destroy($id);

        self::assertNull($this->store->read($id));
    }

    public function test_gc_sweeps_expired_files_keeps_live_ones_and_reports_the_count(): void
    {
        $live = self::id();
        $dead = self::id();
        $this->store->write($live, ['keep' => true], 60);
        $this->store->write($dead, ['gone' => true], -1);

        self::assertSame(1, $this->store->gc());
        self::assertSame(['keep' => true], $this->store->read($live));
        self::assertCount(1, \glob($this->directory . '/sess_*') ?: []);
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
