<?php

declare(strict_types=1);

namespace Kinetis\Orm\Exception;

use LogicException;

/**
 * An EntityManager, or a repository or query it created, was used from a
 * Fiber other than the one that opened it.
 */
final class CrossFiberAccessException extends LogicException
{
    public function __construct()
    {
        parent::__construct(
            'This EntityManager belongs to the Fiber that opened it and was used from another one. Open a separate '
            . 'EntityManager in each concurrent Fiber.',
        );
    }
}
