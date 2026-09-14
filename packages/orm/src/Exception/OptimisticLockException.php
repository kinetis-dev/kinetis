<?php

declare(strict_types=1);

namespace Kinetis\Orm\Exception;

use RuntimeException;

/**
 * A versioned UPDATE or DELETE in EntityManager::flush() affected no row:
 * the row was deleted, or its version changed, after this manager loaded or
 * last flushed the entity. Both are the same stale write. The message names
 * the class and statement, never an identifier or version value.
 */
final class OptimisticLockException extends RuntimeException
{
    public static function stale(string $class, string $statement): self
    {
        return new self(
            "The {$statement} of a {$class} entity affected no row: the row was deleted or its version changed "
            . 'after this EntityManager loaded or last flushed it. Clear the manager, load the entity again and '
            . 'reapply the change.',
        );
    }
}
