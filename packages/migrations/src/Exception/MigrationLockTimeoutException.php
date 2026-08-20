<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Exception;

use RuntimeException;

/**
 * MigrationRunner could not acquire the cross-process migration lock
 * within the configured timeout — another process (typically a second
 * deploy instance starting concurrently) is already running migrate()
 * or rollback().
 */
final class MigrationLockTimeoutException extends RuntimeException
{
    public static function forTimeout(int $timeoutSeconds): self
    {
        return new self(
            "Could not acquire the migration lock within {$timeoutSeconds} second(s) — another process is "
            . 'likely already running migrate() or rollback(). Retry once it finishes, or investigate '
            . 'whether a previous run is genuinely stuck (the lock is held only for the duration of its '
            . "session, so it releases on its own if that process's connection ever closes).",
        );
    }
}
