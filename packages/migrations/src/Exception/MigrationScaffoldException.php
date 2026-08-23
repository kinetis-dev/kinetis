<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Exception;

use RuntimeException;

/**
 * MigrationScaffolder could not create the migrations directory or write
 * the new migration file — a real I/O failure (permissions, disk full,
 * ...), not something silently swallowed.
 */
final class MigrationScaffoldException extends RuntimeException
{
    public static function couldNotCreateDirectory(string $path): self
    {
        return new self("Could not create the migrations directory at \"{$path}\".");
    }

    public static function couldNotWrite(string $path): self
    {
        return new self("Could not write the new migration file at \"{$path}\".");
    }
}
