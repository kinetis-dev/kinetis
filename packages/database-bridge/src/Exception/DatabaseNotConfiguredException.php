<?php

declare(strict_types=1);

namespace Kinetis\DatabaseBridge\Exception;

use RuntimeException;

/**
 * An ORM service was resolved in an application that has kinetis/orm
 * installed and no DB_CONNECTION. "No database" still boots; what needs one
 * fails here, naming the setting.
 */
final class DatabaseNotConfiguredException extends RuntimeException
{
    public static function forOrm(string $service): self
    {
        return new self(
            "{$service} needs the default database connection, and DB_CONNECTION is not set. Set DB_CONNECTION and "
            . 'the other DB_* keys, or build an OrmFactory yourself with OrmFactory::create() over your own connection.',
        );
    }
}
