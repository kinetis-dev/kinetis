<?php

declare(strict_types=1);

namespace Kinetis\Orm\Exception;

use RuntimeException;
use Throwable;

/**
 * OrmFactory::transaction() called COMMIT and received no acknowledgement.
 * The database may already have rolled the transaction back before COMMIT,
 * as PostgreSQL does for a transaction a failed statement aborted, or a
 * COMMIT that was sent may have an unknown outcome. getPrevious() is the
 * driver failure. The manager is closed and wrote nothing into its
 * entities. Neither assuming the work failed nor replaying it is safe.
 */
final class CommitNotAcknowledgedException extends RuntimeException
{
    public function __construct(Throwable $commitFailure)
    {
        parent::__construct(
            'OrmFactory::transaction() received no acknowledged COMMIT: the database may have rolled the transaction '
            . 'back before COMMIT, or a sent COMMIT has an unknown outcome. The EntityManager is closed. Check the '
            . 'database before doing the work again.',
            0,
            $commitFailure,
        );
    }
}
