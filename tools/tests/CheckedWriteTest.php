<?php

declare(strict_types=1);

namespace Kinetis\Tools\Tests;

use CheckedWriteFailure;
use FileOperations;
use NativeFileOperations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../checked-write.php';

/**
 * Wraps the real filesystem and fails exactly one step, so every branch
 * of writeFileChecked() is reachable without a read-only mount, a
 * root-owned directory, or any privilege the test runner may not have.
 */
final class FailingFileOperations implements FileOperations
{
    private readonly NativeFileOperations $real;

    private int $writeCalls = 0;

    public int $closeCalls = 0;

    public int $removeCalls = 0;

    /** @var list<int> */
    public array $modeCalls = [];

    /**
     * @param 'open'|'write'|'flush'|'sync'|'close'|'readBack'|'setMode'|'rename'|null $failAt
     *        the step that reports false
     * @param 'write'|'flush'|'sync'|'close'|'readBack'|'setMode'|'rename'|'remove'|null $throwAt
     *        the step that raises instead
     * @param int|null $shortWriteBytes bytes the first write() stores
     *        before the device stops accepting any more, the shape a
     *        full disk produces
     * @param bool $writeLies whether write() reports bytes it never stored
     */
    public function __construct(
        private readonly ?string $failAt = null,
        private readonly ?int $shortWriteBytes = null,
        private readonly bool $writeLies = false,
        private readonly ?string $throwAt = null,
    ) {
        $this->real = new NativeFileOperations();
    }

    private function maybeThrow(string $step): void
    {
        if ($this->throwAt === $step) {
            throw new RuntimeException("the filesystem raised during {$step}");
        }
    }

    /** @return array{exists: bool, regular: bool, mode: int} */
    public function inspect(string $path): array
    {
        return $this->real->inspect($path);
    }

    public function setMode(string $path, int $mode): bool
    {
        $this->modeCalls[] = $mode;
        $this->maybeThrow('setMode');

        return $this->failAt === 'setMode' ? false : $this->real->setMode($path, $mode);
    }

    /** @return resource|false */
    public function openExclusive(string $path)
    {
        return $this->failAt === 'open' ? false : $this->real->openExclusive($path);
    }

    public function write(mixed $handle, string $data): int|false
    {
        $this->writeCalls++;
        $this->maybeThrow('write');

        if ($this->failAt === 'write') {
            return false;
        }

        if ($this->writeLies) {
            return strlen($data);
        }

        if ($this->shortWriteBytes !== null) {
            return $this->writeCalls === 1
                ? $this->real->write($handle, substr($data, 0, $this->shortWriteBytes))
                : 0;
        }

        return $this->real->write($handle, $data);
    }

    public function flush(mixed $handle): bool
    {
        $this->maybeThrow('flush');

        return $this->failAt === 'flush' ? false : $this->real->flush($handle);
    }

    public function sync(mixed $handle): bool
    {
        $this->maybeThrow('sync');

        return $this->failAt === 'sync' ? false : $this->real->sync($handle);
    }

    public function close(mixed $handle): bool
    {
        $this->closeCalls++;
        $this->maybeThrow('close');

        return $this->failAt === 'close' ? false : $this->real->close($handle);
    }

    public function readBack(string $path): string|false
    {
        $this->maybeThrow('readBack');

        return $this->failAt === 'readBack' ? false : $this->real->readBack($path);
    }

    public function rename(string $from, string $to): bool
    {
        $this->maybeThrow('rename');

        return $this->failAt === 'rename' ? false : $this->real->rename($from, $to);
    }

    public function remove(string $path): bool
    {
        $this->removeCalls++;
        $this->maybeThrow('remove');

        return $this->real->remove($path);
    }
}

final class CheckedWriteTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/kinetis-checked-write-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->directory));
    }

    private function target(): string
    {
        return "{$this->directory}/composer.json";
    }

    /** @return list<string> every entry in the write directory, temporary files included */
    private function leftoverFiles(): array
    {
        $entries = scandir($this->directory);
        self::assertIsArray($entries);
        $entries = array_values(array_filter($entries, static fn (string $e): bool => $e !== '.' && $e !== '..'));
        sort($entries);

        return $entries;
    }

    public function test_a_successful_write_lands_every_byte_and_leaves_no_temporary_file(): void
    {
        $content = str_repeat("{\"a\": \"—\"}\n", 500);

        writeFileChecked($this->target(), $content);

        self::assertSame($content, file_get_contents($this->target()));
        self::assertSame(['composer.json'], $this->leftoverFiles());
    }

    public function test_a_successful_write_replaces_the_previous_content_completely(): void
    {
        file_put_contents($this->target(), "old and much longer content\n");

        writeFileChecked($this->target(), "new\n");

        self::assertSame("new\n", file_get_contents($this->target()));
    }

    public function test_a_failed_open_throws_and_writes_nothing(): void
    {
        file_put_contents($this->target(), "prior\n");

        try {
            writeFileChecked($this->target(), "replacement\n", new FailingFileOperations(failAt: 'open'));
            self::fail('a failed open must throw');
        } catch (CheckedWriteFailure $e) {
            self::assertStringContainsString('temporary file', $e->getMessage());
        }

        self::assertSame("prior\n", file_get_contents($this->target()));
        self::assertSame(['composer.json'], $this->leftoverFiles());
    }

    public function test_a_short_write_throws_and_preserves_the_prior_file(): void
    {
        file_put_contents($this->target(), "prior\n");

        try {
            writeFileChecked($this->target(), str_repeat('x', 100), new FailingFileOperations(shortWriteBytes: 10));
            self::fail('a short write must throw');
        } catch (CheckedWriteFailure $e) {
            self::assertStringContainsString('Short write', $e->getMessage());
        }

        self::assertSame("prior\n", file_get_contents($this->target()));
        self::assertSame(['composer.json'], $this->leftoverFiles());
    }

    public function test_a_write_that_reports_bytes_it_never_stored_is_caught_by_the_read_back(): void
    {
        // The failure mode a byte count alone cannot see: every write()
        // claims success, so only comparing what the file holds
        // against what was handed in catches it.
        writeFileChecked($this->target(), "prior\n");

        try {
            writeFileChecked($this->target(), str_repeat('y', 64), new FailingFileOperations(writeLies: true));
            self::fail('a write that stored nothing must throw');
        } catch (CheckedWriteFailure $e) {
            self::assertStringContainsString('read back', $e->getMessage());
        }

        self::assertSame("prior\n", file_get_contents($this->target()));
        self::assertSame(['composer.json'], $this->leftoverFiles());
    }

    public function test_content_that_does_not_read_back_throws_and_preserves_the_prior_file(): void
    {
        file_put_contents($this->target(), "prior\n");

        try {
            writeFileChecked($this->target(), "replacement\n", new FailingFileOperations(failAt: 'readBack'));
            self::fail('an unverifiable write must throw');
        } catch (CheckedWriteFailure $e) {
            self::assertStringContainsString('read back', $e->getMessage());
        }

        self::assertSame("prior\n", file_get_contents($this->target()));
        self::assertSame(['composer.json'], $this->leftoverFiles());
    }

    public function test_a_failed_rename_throws_and_preserves_the_prior_file(): void
    {
        file_put_contents($this->target(), "prior\n");

        try {
            writeFileChecked($this->target(), "replacement\n", new FailingFileOperations(failAt: 'rename'));
            self::fail('a failed rename must throw');
        } catch (CheckedWriteFailure $e) {
            self::assertStringContainsString('into place', $e->getMessage());
        }

        self::assertSame("prior\n", file_get_contents($this->target()));
        self::assertSame(['composer.json'], $this->leftoverFiles());
    }

    /** @return iterable<string, array{string}> */
    public static function stepsThatCanFail(): iterable
    {
        yield 'write' => ['write'];
        yield 'flush' => ['flush'];
        yield 'sync' => ['sync'];
        yield 'close' => ['close'];
        yield 'readBack' => ['readBack'];
        yield 'setMode' => ['setMode'];
        yield 'rename' => ['rename'];
    }

    /** @param 'write'|'flush'|'sync'|'close'|'readBack'|'setMode'|'rename' $step */
    #[DataProvider('stepsThatCanFail')]
    public function test_a_step_that_reports_false_leaves_the_target_and_the_directory_as_they_were(string $step): void
    {
        file_put_contents($this->target(), "prior\n");

        try {
            writeFileChecked($this->target(), "replacement\n", new FailingFileOperations(failAt: $step));
            self::fail("a failed {$step} must throw");
        } catch (CheckedWriteFailure) {
            self::assertSame("prior\n", file_get_contents($this->target()));
            self::assertSame(['composer.json'], $this->leftoverFiles());
        }
    }

    /** @param 'write'|'flush'|'sync'|'close'|'readBack'|'setMode'|'rename' $step */
    #[DataProvider('stepsThatCanFail')]
    public function test_a_step_that_raises_leaves_the_target_and_the_directory_as_they_were(string $step): void
    {
        file_put_contents($this->target(), "prior\n");

        try {
            writeFileChecked($this->target(), "replacement\n", new FailingFileOperations(throwAt: $step));
            self::fail("a raising {$step} must throw");
        } catch (CheckedWriteFailure $e) {
            self::assertInstanceOf(RuntimeException::class, $e->getPrevious());
            self::assertStringContainsString("raised during {$step}", $e->getMessage());
            self::assertSame("prior\n", file_get_contents($this->target()));
            self::assertSame(['composer.json'], $this->leftoverFiles());
        }
    }

    /**
     * Cleanup runs both steps whatever either one does. A close that
     * raises would otherwise become the reported cause and take the
     * removal with it, leaving a wrong diagnosis and a stray file.
     */
    public function test_a_raising_close_keeps_the_original_cause_and_still_removes_the_temporary_file(): void
    {
        file_put_contents($this->target(), "prior\n");
        $ops = new FailingFileOperations(failAt: 'sync', throwAt: 'close');

        try {
            writeFileChecked($this->target(), "replacement\n", $ops);
            self::fail('the sync failure must surface');
        } catch (CheckedWriteFailure $e) {
            self::assertStringContainsString('Could not sync', $e->getMessage());
            self::assertStringContainsString('closing the temporary file failed', $e->getMessage());
        }

        self::assertSame(1, $ops->closeCalls, 'close is attempted exactly once');
        self::assertSame(1, $ops->removeCalls, 'removal is attempted despite the close');
        self::assertSame("prior\n", file_get_contents($this->target()));
        self::assertSame(['composer.json'], $this->leftoverFiles());
    }

    public function test_a_raising_removal_keeps_the_original_cause_and_says_the_file_is_left(): void
    {
        file_put_contents($this->target(), "prior\n");
        $ops = new FailingFileOperations(failAt: 'rename', throwAt: 'remove');

        try {
            writeFileChecked($this->target(), "replacement\n", $ops);
            self::fail('the rename failure must surface');
        } catch (CheckedWriteFailure $e) {
            self::assertStringContainsString('into place', $e->getMessage());
            self::assertStringContainsString('could not be removed', $e->getMessage());
        }

        self::assertSame("prior\n", file_get_contents($this->target()));
    }

    /**
     * The handle is closed once on the success path, and cleanup must
     * not close it a second time on a failure that comes after.
     */
    public function test_a_failure_after_the_close_does_not_close_the_handle_again(): void
    {
        file_put_contents($this->target(), "prior\n");
        $ops = new FailingFileOperations(failAt: 'rename');

        try {
            writeFileChecked($this->target(), "replacement\n", $ops);
            self::fail('the rename failure must surface');
        } catch (CheckedWriteFailure) {
            self::assertSame(1, $ops->closeCalls);
            self::assertSame(1, $ops->removeCalls);
        }
    }

    /**
     * The temporary file is private while its contents are in doubt;
     * the file that lands is the one everyone reads.
     */
    public function test_a_new_generated_file_lands_readable(): void
    {
        writeFileChecked($this->target(), "{}\n");

        self::assertSame(GENERATED_FILE_MODE, fileperms($this->target()) & 0o777);
    }

    public function test_an_existing_targets_mode_is_kept(): void
    {
        file_put_contents($this->target(), "old\n");
        chmod($this->target(), 0o640);

        writeFileChecked($this->target(), "{}\n");

        self::assertSame(0o640, fileperms($this->target()) & 0o777);
    }

    public function test_a_more_open_existing_mode_is_kept_rather_than_narrowed(): void
    {
        file_put_contents($this->target(), "old\n");
        chmod($this->target(), 0o664);

        writeFileChecked($this->target(), "{}\n");

        self::assertSame(0o664, fileperms($this->target()) & 0o777);
    }

    public function test_the_mode_is_set_before_the_rename_so_no_reader_sees_the_private_one(): void
    {
        $ops = new FailingFileOperations();

        writeFileChecked($this->target(), "{}\n", $ops);

        self::assertSame([GENERATED_FILE_MODE], $ops->modeCalls);
    }

    public function test_a_failed_mode_change_preserves_the_prior_file_and_leaves_no_temporary(): void
    {
        file_put_contents($this->target(), "prior\n");
        chmod($this->target(), 0o640);

        try {
            writeFileChecked($this->target(), "replacement\n", new FailingFileOperations(failAt: 'setMode'));
            self::fail('a failed mode change must throw');
        } catch (CheckedWriteFailure $e) {
            self::assertStringContainsString('Could not set mode 0640', $e->getMessage());
        }

        self::assertSame("prior\n", file_get_contents($this->target()));
        self::assertSame(['composer.json'], $this->leftoverFiles());
    }

    /**
     * The package directory is the write boundary. A link where the
     * generated file belongs would put the content somewhere the
     * manifest never named, so it is refused rather than followed.
     */
    public function test_a_symlinked_target_is_refused_and_left_alone(): void
    {
        $elsewhere = "{$this->directory}/elsewhere.json";
        file_put_contents($elsewhere, "elsewhere\n");
        symlink($elsewhere, $this->target());

        try {
            writeFileChecked($this->target(), "replacement\n");
            self::fail('a symlinked target must be refused');
        } catch (CheckedWriteFailure $e) {
            self::assertStringContainsString('not a regular file', $e->getMessage());
        }

        self::assertTrue(is_link($this->target()));
        self::assertSame("elsewhere\n", file_get_contents($elsewhere));
        self::assertSame(['composer.json', 'elsewhere.json'], $this->leftoverFiles());
    }

    public function test_a_directory_where_the_file_belongs_is_refused(): void
    {
        mkdir($this->target());

        $this->expectException(CheckedWriteFailure::class);

        try {
            writeFileChecked($this->target(), "replacement\n");
        } finally {
            self::assertDirectoryExists($this->target());
        }
    }

    public function test_a_refused_target_is_rejected_before_any_temporary_file_is_created(): void
    {
        mkdir($this->target());
        $ops = new FailingFileOperations();

        try {
            writeFileChecked($this->target(), "replacement\n", $ops);
        } catch (CheckedWriteFailure) {
            self::assertSame(0, $ops->closeCalls);
            self::assertSame(0, $ops->removeCalls);
            self::assertSame(['composer.json'], $this->leftoverFiles());
        }
    }

    public function test_inspect_reports_a_symlink_as_existing_and_not_regular(): void
    {
        $ops = new NativeFileOperations();
        $elsewhere = "{$this->directory}/elsewhere.json";
        file_put_contents($elsewhere, "x\n");
        symlink($elsewhere, $this->target());

        self::assertSame(
            ['exists' => true, 'regular' => false],
            array_intersect_key($ops->inspect($this->target()), ['exists' => 0, 'regular' => 0]),
        );
        self::assertFalse($ops->inspect("{$this->directory}/absent.json")['exists']);
    }

    public function test_the_temporary_file_sits_in_the_targets_own_directory(): void
    {
        // Same directory means same filesystem, which is what makes the
        // final rename atomic rather than a copy.
        $temporary = temporaryPathFor($this->target());

        self::assertSame($this->directory, dirname($temporary));
        self::assertStringStartsWith('.composer.json.', basename($temporary));
        self::assertNotSame($temporary, temporaryPathFor($this->target()));
    }

    public function test_the_temporary_file_is_created_private_to_its_owner(): void
    {
        $ops = new NativeFileOperations();
        $path = "{$this->directory}/private-probe";
        $handle = $ops->openExclusive($path);

        self::assertIsResource($handle);
        $ops->close($handle);
        self::assertSame(0o600, fileperms($path) & 0o777);
    }

    public function test_opening_a_path_that_already_exists_fails_rather_than_truncating_it(): void
    {
        $ops = new NativeFileOperations();
        $path = "{$this->directory}/taken";
        file_put_contents($path, "existing\n");

        self::assertFalse($ops->openExclusive($path));
        self::assertSame("existing\n", file_get_contents($path));
    }
}
