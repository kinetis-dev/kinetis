<?php

declare(strict_types=1);

namespace Kinetis\Cache\Exception;

use RuntimeException;

/**
 * The compiled artifact could not be persisted: the cache directory
 * could not be created, the staged file could not be written whole, it
 * did not read back as what was written, or the rename onto the live
 * path failed.
 *
 * Always a property of this machine — permissions, a full disk, a
 * read-only mount — never of what was compiled, which is
 * {@see UnexportableArtifactException}. `kinetis build` fails on it;
 * the runtime's compile-in-memory fallback logs it once and serves the
 * request from the value it already holds.
 */
final class CacheWriteException extends RuntimeException
{
    public static function couldNotCreateDirectory(string $directory): self
    {
        return new self("Could not create cache directory \"{$directory}\".");
    }

    public static function couldNotWriteTemporaryFile(string $path): self
    {
        return new self("Could not write temporary cache file \"{$path}\".");
    }

    public static function couldNotPublish(string $path): self
    {
        return new self("Could not publish compiled cache to \"{$path}\".");
    }

    public static function couldNotVerify(string $path): self
    {
        return new self(
            "Staged cache file \"{$path}\" did not read back as the artifact it was written from, so it was "
            . 'discarded rather than published.',
        );
    }
}
