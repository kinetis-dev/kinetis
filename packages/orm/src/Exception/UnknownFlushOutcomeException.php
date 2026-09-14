<?php

declare(strict_types=1);

namespace Kinetis\Orm\Exception;

use RuntimeException;
use Throwable;

/**
 * EntityManager::flush() sent COMMIT and the call failed, so whether the
 * server applied the flush is unknown. getPrevious() is the COMMIT
 * failure. The manager is closed and wrote nothing into its entities.
 * Replaying the work on the assumption that it failed can apply it twice.
 */
final class UnknownFlushOutcomeException extends RuntimeException
{
    public function __construct(Throwable $commitFailure)
    {
        parent::__construct(
            'EntityManager::flush() sent COMMIT and it failed: whether the database applied the flush is unknown. '
            . 'This EntityManager is closed. Check the database before doing the work again.',
            0,
            $commitFailure,
        );
    }
}
