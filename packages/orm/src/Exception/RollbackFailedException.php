<?php

declare(strict_types=1);

namespace Kinetis\Orm\Exception;

use RuntimeException;
use Throwable;

/**
 * EntityManager::flush() failed before sending COMMIT, and rolling its
 * transaction back failed too. getPrevious() is the first failure and
 * $rollbackFailure the rollback's. No COMMIT was sent. The manager is
 * closed: redo the work with a new one.
 */
final class RollbackFailedException extends RuntimeException
{
    public function __construct(Throwable $failure, public readonly Throwable $rollbackFailure)
    {
        parent::__construct(
            'EntityManager::flush() failed before COMMIT, and rolling back its transaction failed as well. No COMMIT '
            . 'was sent. This EntityManager is closed: redo the work with a new one.',
            0,
            $failure,
        );
    }
}
