<?php

declare(strict_types=1);

namespace Kinetis\Orm\Exception;

use LogicException;

/** An EntityManager, or a repository or query it created, was used after close(). */
final class ClosedEntityManagerException extends LogicException
{
    public function __construct()
    {
        parent::__construct(
            'This EntityManager is closed. Open a new one with OrmFactory::open(); the entities it loaded stay '
            . 'detached.',
        );
    }
}
