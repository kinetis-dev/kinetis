<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Exception;

use RuntimeException;

/**
 * The migrations table records a migration as applied, but no file with
 * that name exists in the migrations directory anymore — most commonly a
 * migration file deleted (or renamed) after being applied. There's no
 * down() left to run, so rollback() can't proceed silently.
 */
final class MigrationFileMissingException extends RuntimeException
{
    public static function forName(string $name): self
    {
        return new self("No migration file found for \"{$name}\" — it may have been deleted or renamed after being applied.");
    }
}
