<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Exception;

use RuntimeException;

/**
 * An applied migration no longer matches what the ledger recorded for
 * it: its file is gone or unreadable, or its contents hash to something
 * other than the checksum stored when it was applied. Either way the SQL
 * that was applied is not the SQL on disk, so the command stops before
 * any up(), down() or ledger write runs.
 *
 * A message names the migration and the reason and nothing else: a
 * filesystem path or a checksum in an operator-facing error adds
 * deployment layout to the logs without telling anyone what to do about
 * it. Restoring the migration file that was deployed is the answer to
 * both reasons.
 */
final class MigrationIntegrityException extends RuntimeException
{
    public static function forMissingSource(string $migration): self
    {
        return new self(
            "Migration \"{$migration}\" is recorded as applied, but its source is missing or unreadable — "
            . 'restore the migration file that was deployed, then run the command again.',
        );
    }

    public static function forChecksumMismatch(string $migration): self
    {
        return new self(
            "Migration \"{$migration}\" no longer matches the source recorded when it was applied — "
            . 'restore the migration file that was deployed, then run the command again.',
        );
    }
}
