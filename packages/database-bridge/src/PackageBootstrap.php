<?php

declare(strict_types=1);

namespace Kinetis\DatabaseBridge;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\PackageBootstrapInterface;
use Kinetis\Container\RequestScope;
use Kinetis\DatabaseBridge\Exception\DatabaseNotConfiguredException;
use Kinetis\Orm\EntityManager;
use Kinetis\Orm\OrmFactory;
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
 * its own, and is closed when the application scope is disposed.
 * Without DB_CONNECTION no connection is built — "no database" is a
 * configuration, not an error. The application's bootstrap.php runs
 * after this and wins on a shared binding, keeping ownership of the
 * link it binds; named (non-default) connections stay explicit
 * application wiring.
 *
 * kinetis/orm is optional and detected with class_exists(). With it and
 * DB_CONNECTION, OrmFactory and a lazy request-scoped EntityManager are
 * bound; with it and no DB_CONNECTION, both resolve to
 * DatabaseNotConfiguredException; without it, neither is bound.
 */
final class PackageBootstrap implements PackageBootstrapInterface
{
    #[\Override]
    public function register(AppScope $app, Config $config): void
    {
        $app->onRequestScopeCreated(self::bindTransactionGuard(...));
        $orm = class_exists(OrmFactory::class);

        if ($config->get('DB_CONNECTION') === null) {
            if ($orm) {
                self::refuseOrm($app);
            }

            return;
        }

        $link = ConnectionFactory::fromConfig($config);
        $contract = $link instanceof MysqlLink ? MysqlLink::class : PostgresLink::class;

        // This package opened it, so this package closes it when the
        // worker ends — registered ahead of the binding so a later
        // bootstrap or boot failure still closes it, and holding this
        // exact object so an application binding its own link owns that
        // one.
        $app->onDispose($link->close(...));
        $app->instance($contract, $link);

        if ($orm) {
            self::bindOrm($app, $contract);
        }
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

    /**
     * One OrmFactory per worker, built on first use from whatever is bound
     * under $contract when it is resolved — an application's own binding
     * included — and the OrmMetadata the framework bound from the AOT
     * cache. Each scope's EntityManager is opened on its first resolution,
     * in the Fiber resolving it, and its close() registered on that scope
     * then.
     *
     * @param class-string<MysqlLink>|class-string<PostgresLink> $contract
     */
    private static function bindOrm(AppScope $app, string $contract): void
    {
        $app->bind(OrmFactory::class, static function (AppScope $app) use ($contract): OrmFactory {
            /** @var MysqlLink|PostgresLink $link */
            $link = $app->get($contract);
            /** @var OrmMetadata $metadata */
            $metadata = $app->get(OrmMetadata::class);

            return OrmFactory::create($link, $metadata->registry());
        });

        $app->onRequestScopeCreated(static function (RequestScope $scope): void {
            $scope->bind(EntityManager::class, static function (RequestScope $scope): EntityManager {
                /** @var OrmFactory $factory */
                $factory = $scope->get(OrmFactory::class);
                $manager = $factory->open();
                $scope->onDispose($manager->close(...));

                return $manager;
            });
        });
    }

    /**
     * Both ORM services are bound to a named failure, so neither reaches
     * autowiring, whose refusal of their non-public constructors would not
     * name the missing setting.
     */
    private static function refuseOrm(AppScope $app): void
    {
        $app->bind(OrmFactory::class, static fn (): never => throw DatabaseNotConfiguredException::forOrm(OrmFactory::class));
        $app->bind(EntityManager::class, static fn (): never => throw DatabaseNotConfiguredException::forOrm(EntityManager::class));
    }
}
