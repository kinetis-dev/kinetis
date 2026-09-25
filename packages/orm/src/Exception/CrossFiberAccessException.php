<?php

declare(strict_types=1);

namespace Kinetis\Orm\Exception;

use LogicException;

/**
 * An EntityManager, or a repository or query it created, or an
 * EntityManagerRegistry was used from a Fiber other than the one that
 * opened it.
 */
final class CrossFiberAccessException extends LogicException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function manager(): self
    {
        return new self(
            'This EntityManager belongs to the Fiber that opened it and was used from another one. Open a separate '
            . 'EntityManager in each concurrent Fiber.',
        );
    }

    public static function registry(): self
    {
        return new self(
            'This EntityManagerRegistry belongs to the Fiber that created it and was used from another one. Create a '
            . 'separate EntityManagerRegistry in each concurrent Fiber.',
        );
    }
}
