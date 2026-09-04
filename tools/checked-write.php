<?php

declare(strict_types=1);

/**
 * The one checked write every tool here uses to replace a generated
 * file — packages/<key>/composer.json and packages.manifest.json alike.
 *
 * A plain file_put_contents() reports a short write as a smaller return
 * value nobody reads, and truncates the target before it knows whether
 * the new content will land. Both matter here: these files are the
 * inputs the release pipeline tags from, and half of one is worse than
 * none of it.
 *
 * The sequence is: create a private temporary file in the target's own
 * directory (so the rename below stays on one filesystem and is
 * therefore atomic), write every byte and confirm the count, flush and
 * sync, read the file back and compare it byte for byte, give it the
 * mode the target is meant to have, then rename over the target. Any
 * failure before the rename throws with the target untouched; the
 * temporary file is removed on the way out.
 *
 * Every filesystem call goes through FileOperations so a test can fail
 * any one step on demand — a short write, an open that never succeeds, a
 * rename that refuses — without needing a read-only mount or a
 * privilege the test runner doesn't have.
 */

final class CheckedWriteFailure extends RuntimeException
{
}

/**
 * The mode a generated file that doesn't exist yet is created with.
 * These files are committed and read by everyone; only the temporary
 * one is private, and only while it is incomplete.
 */
const GENERATED_FILE_MODE = 0o644;

interface FileOperations
{
    /**
     * What is at $path, without following a link — a symlink reports as
     * existing and not regular, which is what stops it being replaced.
     *
     * @return array{exists: bool, regular: bool, mode: int}
     */
    public function inspect(string $path): array;

    public function setMode(string $path, int $mode): bool;

    /**
     * Creates $path, failing if it already exists, readable and
     * writable only by its owner.
     *
     * @return resource|false
     */
    public function openExclusive(string $path);

    /**
     * @param resource $handle
     * @return int|false bytes written
     */
    public function write(mixed $handle, string $data): int|false;

    /** @param resource $handle */
    public function flush(mixed $handle): bool;

    /**
     * Forces the bytes to the device where the platform supports it.
     * Reports true when there is nothing to force.
     *
     * @param resource $handle
     */
    public function sync(mixed $handle): bool;

    /** @param resource $handle */
    public function close(mixed $handle): bool;

    public function readBack(string $path): string|false;

    public function rename(string $from, string $to): bool;

    public function remove(string $path): bool;
}

final class NativeFileOperations implements FileOperations
{
    /** @return array{exists: bool, regular: bool, mode: int} */
    #[\Override]
    public function inspect(string $path): array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);

        if ($stat === false) {
            return ['exists' => false, 'regular' => false, 'mode' => 0];
        }

        return [
            'exists' => true,
            'regular' => ($stat['mode'] & 0o170000) === 0o100000,
            'mode' => $stat['mode'] & 0o7777,
        ];
    }

    #[\Override]
    public function setMode(string $path, int $mode): bool
    {
        return @chmod($path, $mode);
    }

    /** @return resource|false */
    #[\Override]
    public function openExclusive(string $path)
    {
        $previous = umask(0o077);

        try {
            return @fopen($path, 'xb');
        } finally {
            umask($previous);
        }
    }

    #[\Override]
    public function write(mixed $handle, string $data): int|false
    {
        \assert(is_resource($handle));

        return @fwrite($handle, $data);
    }

    #[\Override]
    public function flush(mixed $handle): bool
    {
        \assert(is_resource($handle));

        return @fflush($handle);
    }

    #[\Override]
    public function sync(mixed $handle): bool
    {
        \assert(is_resource($handle));

        // fsync() is unavailable on some builds and refuses some stream
        // types; neither is a reason to fail a write whose bytes are
        // already flushed out of userland.
        if (!function_exists('fsync')) {
            return true;
        }

        return @fsync($handle);
    }

    #[\Override]
    public function close(mixed $handle): bool
    {
        \assert(is_resource($handle));

        return @fclose($handle);
    }

    #[\Override]
    public function readBack(string $path): string|false
    {
        clearstatcache(true, $path);

        return @file_get_contents($path);
    }

    #[\Override]
    public function rename(string $from, string $to): bool
    {
        return @rename($from, $to);
    }

    #[\Override]
    public function remove(string $path): bool
    {
        return @unlink($path);
    }
}

/**
 * Replaces $path with $content, or throws leaving $path as it was.
 *
 * @throws CheckedWriteFailure
 */
function writeFileChecked(string $path, string $content, ?FileOperations $ops = null): void
{
    $ops ??= new NativeFileOperations();
    $target = $ops->inspect($path);

    // A symlink, a directory or a device where the generated file
    // belongs is not something to replace: the rename would either
    // fail or put the content somewhere the manifest never named.
    if ($target['exists'] && !$target['regular']) {
        throw new CheckedWriteFailure("{$path} is not a regular file, so it is not something to replace");
    }

    $mode = $target['exists'] ? $target['mode'] : GENERATED_FILE_MODE;
    $temporary = temporaryPathFor($path);
    $handle = $ops->openExclusive($temporary);

    if ($handle === false) {
        throw new CheckedWriteFailure("Could not create a temporary file next to {$path}");
    }

    $closed = false;

    try {
        writeEveryByte($ops, $handle, $content, $temporary, $path);

        if (!$ops->flush($handle)) {
            throw new CheckedWriteFailure("Could not flush the temporary file for {$path}");
        }

        if (!$ops->sync($handle)) {
            throw new CheckedWriteFailure("Could not sync the temporary file for {$path}");
        }

        $closed = true;

        if (!$ops->close($handle)) {
            throw new CheckedWriteFailure("Could not close the temporary file for {$path}");
        }

        $readBack = $ops->readBack($temporary);

        if ($readBack !== $content) {
            $landed = $readBack === false ? 'nothing readable' : strlen($readBack) . ' bytes';

            throw new CheckedWriteFailure(
                'Wrote ' . strlen($content) . " bytes for {$path} but read back {$landed}",
            );
        }

        // With the contents proven, the file takes the mode the target
        // is meant to have — before the rename rather than after, since
        // a reader can open it the instant the rename lands.
        if (!$ops->setMode($temporary, $mode)) {
            throw new CheckedWriteFailure(
                'Could not set mode 0' . decoct($mode) . " on the temporary file for {$path}",
            );
        }

        if (!$ops->rename($temporary, $path)) {
            throw new CheckedWriteFailure("Could not move the completed temporary file into place at {$path}");
        }
    } catch (Throwable $primary) {
        throw new CheckedWriteFailure(
            $primary->getMessage() . cleanUpAfterFailure($ops, $handle, $temporary, $closed),
            previous: $primary,
        );
    }
}

/**
 * Closing and removing after a failed write, each attempted whatever the
 * other does. Neither is allowed to replace the failure that brought us
 * here: a close that throws would otherwise become the reported cause and
 * skip the removal, leaving both a wrong diagnosis and a stray file.
 *
 * What comes back is a note for the message — the names of the cleanup
 * steps that didn't work, nothing read off the filesystem.
 *
 * @param resource|false $handle
 */
function cleanUpAfterFailure(FileOperations $ops, mixed $handle, string $temporary, bool $alreadyClosed): string
{
    $notes = [];

    if (!$alreadyClosed && $handle !== false) {
        try {
            if (!$ops->close($handle)) {
                $notes[] = 'the temporary file did not close';
            }
        } catch (Throwable) {
            $notes[] = 'closing the temporary file failed';
        }
    }

    try {
        if (!$ops->remove($temporary)) {
            $notes[] = "{$temporary} is left behind";
        }
    } catch (Throwable) {
        $notes[] = "{$temporary} could not be removed";
    }

    return $notes === [] ? '' : ' (' . implode('; ', $notes) . ')';
}

/**
 * fwrite() is allowed to write less than it was given, so a single call
 * proves nothing. Loops until every byte is accounted for, and treats a
 * call that makes no progress as a failure rather than spinning.
 *
 * @param resource $handle
 * @throws CheckedWriteFailure
 */
function writeEveryByte(FileOperations $ops, mixed $handle, string $content, string $temporary, string $path): void
{
    $total = strlen($content);
    $written = 0;

    while ($written < $total) {
        $result = $ops->write($handle, substr($content, $written));

        if ($result === false || $result <= 0) {
            throw new CheckedWriteFailure(
                "Short write for {$path}: {$written} of {$total} bytes reached {$temporary}",
            );
        }

        $written += $result;
    }
}

/**
 * A name in the target's own directory — same filesystem, so the final
 * rename is atomic — that no concurrent run can collide with and no
 * directory listing sweeps up as content.
 */
function temporaryPathFor(string $path): string
{
    $directory = dirname($path);
    $name = basename($path);

    return "{$directory}/.{$name}." . bin2hex(random_bytes(8)) . '.tmp';
}
