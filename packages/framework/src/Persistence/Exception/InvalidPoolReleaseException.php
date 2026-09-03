<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Exception;

use RuntimeException;

final class InvalidPoolReleaseException extends RuntimeException
{
    /**
     * $connection is not currently checked out by this Pool at all —
     * either it was never created by this pool's own factory, or it was
     * but has since been permanently discarded (a failed health check,
     * for instance). Pool retains no history of a discarded member, so
     * it cannot distinguish "never owned" from "no longer owned" — both
     * are, from here, simply "not something to hand back."
     */
    public static function foreignMember(): self
    {
        return new self(
            'Cannot release a connection this Pool does not currently hold checked out — '
            . 'either it was never created by this pool, or it has already been discarded. '
            . 'Releasing it would let this Pool track a connection it does not actually own.',
        );
    }

    /**
     * $connection is a real member of this Pool, but it is already idle
     * — nobody currently holds it checked out. Releasing it again (a
     * double release, or simply calling release() on something that was
     * never re-acquired since its last release) would let two different
     * callers acquire() the identical connection at the same time.
     */
    public static function alreadyIdle(): self
    {
        return new self(
            'Cannot release a connection that is already idle in this Pool — it is not '
            . 'currently checked out by anyone. Releasing it again would let two callers '
            . 'acquire the identical connection at once.',
        );
    }
}
