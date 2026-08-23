<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Tests;

use Kinetis\Migrations\Exception\MigrationScaffoldException;
use Kinetis\Migrations\Migration;
use Kinetis\Migrations\MigrationScaffolder;
use Kinetis\Migrations\Tests\Fixtures\FailingWriteStreamWrapper;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class MigrationScaffolderTest extends TestCase
{
    private string $migrationsPath;

    protected function setUp(): void
    {
        $this->migrationsPath = sys_get_temp_dir() . '/kinetis-migrations-scaffolder-test-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->migrationsPath)) {
            foreach (glob($this->migrationsPath . '/*.php') ?: [] as $file) {
                unlink($file);
            }

            rmdir($this->migrationsPath);
        }
    }

    public function test_create_creates_the_migrations_directory_when_missing(): void
    {
        self::assertDirectoryDoesNotExist($this->migrationsPath);

        MigrationScaffolder::create($this->migrationsPath, 'create orders table');

        self::assertDirectoryExists($this->migrationsPath);
    }

    public function test_create_names_the_file_with_a_timestamp_prefix_and_a_slugified_description(): void
    {
        $path = MigrationScaffolder::create($this->migrationsPath, 'Create Orders Table!!');

        self::assertMatchesRegularExpression(
            '/^\d{14}_create_orders_table\.php$/',
            basename($path),
        );
    }

    public function test_create_writes_a_file_that_returns_a_working_migration(): void
    {
        $path = MigrationScaffolder::create($this->migrationsPath, 'anything');

        /** @var Migration $migration */
        $migration = require $path;

        self::assertInstanceOf(Migration::class, $migration);
    }

    public function test_an_empty_description_falls_back_to_a_generic_name(): void
    {
        $path = MigrationScaffolder::create($this->migrationsPath, '!!!');

        self::assertMatchesRegularExpression('/^\d{14}_migration\.php$/', basename($path));
    }

    public function test_a_same_second_same_description_collision_gets_a_distinct_file_instead_of_being_overwritten(): void
    {
        mkdir($this->migrationsPath);
        $timestamp = (new DateTimeImmutable())->format('YmdHis');
        $existingPath = "{$this->migrationsPath}/{$timestamp}_anything.php";
        file_put_contents($existingPath, 'original content');

        $newPath = MigrationScaffolder::create($this->migrationsPath, 'anything');

        self::assertNotSame($existingPath, $newPath);
        self::assertSame('original content', file_get_contents($existingPath));
        self::assertFileExists($newPath);
        self::assertMatchesRegularExpression('/^\d{14}_anything_[0-9a-f]{6}\.php$/', basename($newPath));
    }

    public function test_create_throws_when_the_migrations_path_is_blocked_by_a_plain_file(): void
    {
        file_put_contents($this->migrationsPath, 'not a directory');

        $this->expectException(MigrationScaffoldException::class);
        $this->expectExceptionMessage('Could not create the migrations directory');

        try {
            MigrationScaffolder::create($this->migrationsPath, 'anything');
        } finally {
            unlink($this->migrationsPath);
        }
    }

    /**
     * A real, non-obvious finding while writing this test: PHP's own
     * fwrite() already loops internally against a userland stream
     * wrapper's stream_write(), retrying for as long as each call keeps
     * making forward progress — a short write alone doesn't reach
     * userland code at all, PHP absorbs it silently. The genuine gap is
     * a write that STALLS (some real progress, then zero further
     * progress, e.g. a disk that's now completely full): PHP's own loop
     * gives up at that point and returns fwrite()'s result as a real,
     * positive byte count smaller than what was asked for — not false.
     * The old code's `if ($written === false)` check missed this
     * entirely, closed the handle, and returned the path as if nothing
     * had gone wrong, leaving a truncated, syntactically invalid
     * migration file on disk with no error raised at all — worse than
     * "an exception left a stray temp file behind": a silent, reported
     * success delivering corrupt content. FailingWriteStreamWrapper
     * reproduces exactly this: real progress on the first call, zero
     * progress on every call after.
     *
     * The write-failure branch used to unlink() before fclose() ever ran
     * (a "||" short-circuit skipped fclose() entirely once writeAll()
     * had already returned false) — harmless on Linux, where POSIX
     * permits unlinking a still-open file, but not portable, and not
     * what the code's own cleanup contract should depend on. $eventLog
     * pins the real order across both wrapper calls.
     */
    public function test_create_throws_and_removes_the_file_when_a_write_stalls_with_zero_further_progress(): void
    {
        mkdir($this->migrationsPath);
        FailingWriteStreamWrapper::$backingDirectory = $this->migrationsPath;
        FailingWriteStreamWrapper::$mode = FailingWriteStreamWrapper::MODE_STALL;
        FailingWriteStreamWrapper::$eventLog = [];
        stream_wrapper_register(FailingWriteStreamWrapper::SCHEME, FailingWriteStreamWrapper::class);

        try {
            try {
                MigrationScaffolder::create(FailingWriteStreamWrapper::SCHEME . '://', 'anything');
                self::fail('Expected MigrationScaffoldException.');
            } catch (MigrationScaffoldException) {
                // Expected — writing was made to stall.
            }

            $stray = array_values(array_filter(
                scandir($this->migrationsPath) ?: [],
                static fn (string $file): bool => str_ends_with($file, '.php'),
            ));

            self::assertSame([], $stray, 'An incomplete migration stub was left behind after a failed write.');
            self::assertSame(
                ['stream_close', 'unlink'],
                FailingWriteStreamWrapper::$eventLog,
                'The file must be closed before it is unlinked.',
            );
        } finally {
            stream_wrapper_unregister(FailingWriteStreamWrapper::SCHEME);
        }
    }

    /**
     * The STALL test above proves writeAll() detects a genuine failure,
     * but every one of its stalled calls returns 0 regardless of what
     * data it was given — meaning it can't tell apart a correct
     * `fwrite($handle, substr($data, $offset))` from an incorrect one
     * that forgot the substr() (rewriting the *whole* string on every
     * call) or the offset accumulation (`$offset += $written` losing its
     * `+`). This mode always succeeds, a few bytes at a time, so the
     * exact bytes each call receives — and therefore the final file's
     * exact content — depends on both being correct.
     */
    public function test_create_writes_the_exact_correct_content_when_writing_happens_in_several_small_chunks(): void
    {
        $referencePath = MigrationScaffolder::create($this->migrationsPath, 'reference');
        $referenceContent = file_get_contents($referencePath);
        self::assertIsString($referenceContent);
        unlink($referencePath);

        FailingWriteStreamWrapper::$backingDirectory = $this->migrationsPath;
        FailingWriteStreamWrapper::$mode = FailingWriteStreamWrapper::MODE_CHUNKED;
        stream_wrapper_register(FailingWriteStreamWrapper::SCHEME, FailingWriteStreamWrapper::class);

        try {
            $path = MigrationScaffolder::create(FailingWriteStreamWrapper::SCHEME . '://', 'anything');
            $realPath = $this->migrationsPath . '/' . basename($path);

            // Byte-for-byte, not merely "requiring it doesn't crash": a
            // writeAll() that forgot substr() (rewriting the whole
            // string every call, rather than just what's left) would
            // still often require() successfully -- PHP's return exits
            // at the *first* valid "return new class ...;" it reaches,
            // silently discarding whatever duplicated content trails
            // after it, which a mere assertInstanceOf() check can't see.
            self::assertSame($referenceContent, file_get_contents($realPath));
        } finally {
            stream_wrapper_unregister(FailingWriteStreamWrapper::SCHEME);
        }
    }
}
