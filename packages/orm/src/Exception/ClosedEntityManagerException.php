<?php

declare(strict_types=1);

namespace Kinetis\Orm\Exception;

use LogicException;

/**
 * An EntityManager, or a repository or query it created, was used after
 * close(), or an EntityManagerRegistry was asked for a manager after its
 * close().
 */
final class ClosedEntityManagerException extends LogicException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function manager(): self
    {
        return new self(
            'This EntityManager is closed, and the entities it loaded stay detached. Start a new unit of work with '
            . 'OrmFactory::open() or OrmFactory::transaction().',
        );
    }

    public static function registry(): self
    {
        return new self(
            'This EntityManagerRegistry is closed, and so is every EntityManager it opened. Start a new unit of work '
            . 'with EntityManagerRegistry::create().',
        );
    }
}
