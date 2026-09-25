<?php

declare(strict_types=1);

namespace Kinetis\DatabaseBridge\Exception;

use Kinetis\Config\Config;
use RuntimeException;

/**
 * An ORM service was resolved in an application that has kinetis/orm
 * installed and lacks a connection it needs: DB_CONNECTION for the default
 * one, or a named connection's DB_{NAME}_CONNECTION. "No database" still
 * boots; what needs one fails here, naming the setting.
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

    public static function forConnection(string $service, string $connection): self
    {
        $key = Config::scopedKey('DB_CONNECTION', $connection);
        $prefix = Config::scopedKey('DB_*', $connection);

        return new self(
            "{$service} needs the \"{$connection}\" database connection, which an entity names, and {$key} is not "
            . "set. Set {$key} and the other {$prefix} keys, or bind that connection's MysqlLink or PostgresLink as "
            . "\"db.{$connection}\" in bootstrap.php.",
        );
    }
}
