<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Exception;

use RuntimeException;

/**
 * MigrationRunner's RELEASE_LOCK()/pg_advisory_unlock() call reported
 * that this session did not hold the migration lock at release time.
 * Neither backend throws for this on its own — MySQL's RELEASE_LOCK()
 * returns 0 (a different session holds the lock) or NULL (it was never
 * acquired at all); Postgres's pg_advisory_unlock() returns false for
 * the equivalent case — so MigrationRunner checks the returned value
 * explicitly rather than assuming a successful query means a
 * successful release.
 *
 * The most likely real cause: acquireLock() and this release call ran
 * on two different physical connections. MigrationContext's own
 * connection-building seam forces a single native connection for the
 * migrate:* command path specifically to close this; a manually
 * constructed MigrationRunner given a link that isn't session-stable
 * for its whole lifetime can still hit this.
 */
final class MigrationLockReleaseException extends RuntimeException
{
    public static function didNotRelease(): self
    {
        return new self(
            'The migration lock was not released by this session — the backend reported it was not held here '
            . '(a different session holds it, or it was never acquired). This usually means the acquire and '
            . 'release calls ran on two different physical connections; verify the database link passed to '
            . 'MigrationRunner resolves to a single, session-stable connection for its whole lifetime.',
        );
    }
}
