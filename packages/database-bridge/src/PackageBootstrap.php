<?php

declare(strict_types=1);

namespace Kinetis\DatabaseBridge;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\PackageBootstrapInterface;
use Kinetis\Container\RequestScope;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Persistence\TransactionGuard;
use Psr\Log\LoggerInterface;

/**
 * Declared via extra.kinetis and run by the framework ahead of the
 * application's own bootstrap.php.
 *
 * Every RequestScope — an HTTP request, a job, an MCP message, a command
 * — gets a lazy TransactionGuard binding. The guard is built the first
 * time that unit of work resolves it, and its rollbackDangling() is
 * registered on that same scope's disposal then; a unit that never
 * resolves it constructs none.
 *
 * With DB_CONNECTION configured, the default connection is built and
 * bound under its dialect contract, so application code
 * constructor-injects MysqlLink/PostgresLink with no bootstrap code of
 * its own. Without DB_CONNECTION no connection is built — "no database"
 * is a configuration, not an error. The application's bootstrap.php
 * runs after this and wins on a shared binding; named (non-default)
 * connections stay explicit application wiring.
 */
final class PackageBootstrap implements PackageBootstrapInterface
{
    #[\Override]
    public function register(AppScope $app, Config $config): void
    {
        $app->onRequestScopeCreated(self::bindTransactionGuard(...));

        if ($config->get('DB_CONNECTION') === null) {
            return;
        }

        $link = ConnectionFactory::fromConfig($config);

        $app->instance($link instanceof MysqlLink ? MysqlLink::class : PostgresLink::class, $link);
    }

    private static function bindTransactionGuard(RequestScope $scope): void
    {
        // The scope resolving the binding is the one passed in, so the
        // cleanup lands on the unit of work that owns the guard.
        $scope->bind(TransactionGuard::class, static function (RequestScope $scope): TransactionGuard {
            /** @var LoggerInterface $logger */
            $logger = $scope->get(LoggerInterface::class);
            $guard = new TransactionGuard($logger);
            $scope->onDispose($guard->rollbackDangling(...));

            return $guard;
        });
    }
}
