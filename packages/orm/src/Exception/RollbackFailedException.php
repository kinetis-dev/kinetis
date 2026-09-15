<?php

declare(strict_types=1);

namespace Kinetis\Orm\Exception;

use RuntimeException;
use Throwable;

/**
 * An ORM-owned transaction — EntityManager::flush()'s, or
 * OrmFactory::transaction()'s — failed before COMMIT, and rolling it back
 * failed too. getPrevious() is the first failure and $rollbackFailure the
 * rollback's. No COMMIT was sent. The manager is closed: redo the work with
 * a new one.
 */
final class RollbackFailedException extends RuntimeException
{
    public function __construct(Throwable $failure, public readonly Throwable $rollbackFailure)
    {
        parent::__construct(
            'An ORM-owned transaction failed before COMMIT, and rolling it back failed as well. No COMMIT was sent. '
            . 'The EntityManager is closed: redo the work with a new one.',
            0,
            $failure,
        );
    }
}
