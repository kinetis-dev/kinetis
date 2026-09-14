<?php

declare(strict_types=1);

namespace Kinetis\Orm\Exception;

use RuntimeException;

/** EntityRepository::findOrFail() found no row. The identifier is left out: it can be a secret. */
final class EntityNotFoundException extends RuntimeException
{
    public static function forClass(string $class): self
    {
        return new self("No {$class} entity exists with the requested identifier.");
    }
}
