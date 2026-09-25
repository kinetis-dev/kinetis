<?php

declare(strict_types=1);

namespace Kinetis\DatabaseBridge;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\PackageBootstrapInterface;
use Kinetis\Container\RequestScope;
use Kinetis\DatabaseBridge\Exception\DatabaseNotConfiguredException;
use Kinetis\Orm\EntityManager;
use Kinetis\Orm\EntityManagerRegistry;
use Kinetis\Orm\OrmFactory;
use Kinetis\Orm\OrmFactoryRegistry;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\TransactionGuard;
use LogicException;
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
 * bound under its dialect contract, MysqlLink or PostgresLink, and is
 * closed when the application scope is disposed. SqlLink is bound as an
 * uncached alias that resolves the dialect contract on every lookup, so
 * application code constructor-injects either type with no bootstrap
 * code of its own and both return the same link. Without DB_CONNECTION
 * no connection is built and no link contract is bound — "no database"
 * is a configuration, not an error. The application's bootstrap.php runs
 * after this and wins on a shared binding, keeping ownership of the
 * link it binds: a replaced dialect binding is what SqlLink then
 * returns, and an explicit SqlLink binding replaces the alias. A named
 * connection is bound for SQL only by the application.
 *
 * kinetis/orm is optional and detected with class_exists(). With it, an
 * OrmFactoryRegistry is bound for the worker and a lazy
 * EntityManagerRegistry on every RequestScope, each covering every
 * connection an entity names; see bindOrm(). With DB_CONNECTION,
 * OrmFactory and a request-scoped EntityManager are the registries'
 * default-connection entries; without it, both resolve to
 * DatabaseNotConfiguredException. Without kinetis/orm, no ORM service is
 * bound.
 */
final class PackageBootstrap implements PackageBootstrapInterface
{
    #[\Override]
    public function register(AppScope $app, Config $config): void
    {
        $app->onRequestScopeCreated(self::bindTransactionGuard(...));
        $contract = null;

        if ($config->get('DB_CONNECTION') !== null) {
            $link = ConnectionFactory::fromConfig($config);
            $contract = $link instanceof MysqlLink ? MysqlLink::class : PostgresLink::class;

            // This package opened it, so this package closes it when the
            // worker ends — registered ahead of the binding so a later
            // bootstrap or boot failure still closes it, and holding this
            // exact object so an application binding its own link owns that
            // one.
            $app->onDispose($link->close(...));
            $app->instance($contract, $link);
            // Unshared and resolved through the dialect binding on every
            // lookup, so a replacement of that binding is returned here even
            // after SqlLink was already resolved once.
            $app->bind(SqlLink::class, static function (AppScope $app) use ($contract): SqlLink {
                /** @var MysqlLink|PostgresLink */
                return $app->get($contract);
            }, shared: false);
        }

        if (class_exists(OrmFactory::class)) {
            self::bindOrm($app, $config, $contract);
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
     * One OrmFactoryRegistry per worker, built on first use from the
     * OrmMetadata bound when it is resolved — the framework's compiled
     * instance or an application's replacement — over a link for the
     * default connection when DB_CONNECTION is set and for every
     * connection an entity names. The default link is whatever is bound
     * under $contract then; a named one is resolved by link(). Each
     * scope's EntityManagerRegistry is created on its first resolution, in
     * the Fiber resolving it, and its close() registered on that scope
     * then. OrmFactory and EntityManager resolve the default connection's
     * entries of the two registries on every lookup.
     *
     * @param class-string<MysqlLink>|class-string<PostgresLink>|null $contract null without DB_CONNECTION
     */
    private static function bindOrm(AppScope $app, Config $config, ?string $contract): void
    {
        // Named links this package built, kept across failed resolutions so
        // a retry after a configuration error never builds a second client.
        $built = [];

        $app->bind(OrmFactoryRegistry::class, static function (AppScope $app) use ($config, $contract, &$built): OrmFactoryRegistry {
            /** @var OrmMetadata $metadata */
            $metadata = $app->get(OrmMetadata::class);
            $registry = $metadata->registry();
            /** @var array<string, MysqlLink|PostgresLink> $links */
            $links = $contract === null ? [] : ['default' => $app->get($contract)];

            foreach ($registry->connections() as $connection) {
                $links[$connection] ??= self::link($app, $config, $connection, $built);
            }

            return OrmFactoryRegistry::create($links, $registry);
        });

        $app->onRequestScopeCreated(static function (RequestScope $scope) use ($contract): void {
            $scope->bind(EntityManagerRegistry::class, static function (RequestScope $scope): EntityManagerRegistry {
                /** @var OrmFactoryRegistry $factories */
                $factories = $scope->get(OrmFactoryRegistry::class);
                $managers = EntityManagerRegistry::create($factories);
                $scope->onDispose($managers->close(...));

                return $managers;
            });

            if ($contract !== null) {
                $scope->bind(EntityManager::class, static function (RequestScope $scope): EntityManager {
                    /** @var EntityManagerRegistry $managers */
                    $managers = $scope->get(EntityManagerRegistry::class);

                    return $managers->manager('default');
                }, shared: false);
            }
        });

        if ($contract !== null) {
            $app->bind(OrmFactory::class, static function (AppScope $app): OrmFactory {
                /** @var OrmFactoryRegistry $factories */
                $factories = $app->get(OrmFactoryRegistry::class);

                return $factories->factory('default');
            }, shared: false);

            return;
        }

        // Bound to a named failure, so neither reaches autowiring, whose
        // refusal of their non-public constructors would not name the
        // missing setting.
        $app->bind(OrmFactory::class, static fn (): never => throw DatabaseNotConfiguredException::forOrm(OrmFactory::class));
        $app->bind(EntityManager::class, static fn (): never => throw DatabaseNotConfiguredException::forOrm(EntityManager::class));
    }

    /**
     * A named connection's link: the application's "db.{name}" binding,
     * which stays the application's to close, or else one built from the
     * connection's DB_{NAME}_* keys and closed with the application scope.
     * The connection key is checked first, so an unconfigured connection is
     * reported by that key rather than by whichever other key is read
     * first. The default connection arrives here only without
     * DB_CONNECTION.
     *
     * @param array<string, MysqlLink|PostgresLink> $built
     */
    private static function link(AppScope $app, Config $config, string $connection, array &$built): MysqlLink|PostgresLink
    {
        if ($connection === 'default') {
            throw DatabaseNotConfiguredException::forOrm(OrmFactoryRegistry::class);
        }

        $id = "db.{$connection}";

        if ($app->has($id)) {
            $link = $app->get($id);

            if (!$link instanceof MysqlLink && !$link instanceof PostgresLink) {
                throw new LogicException(
                    "\"{$id}\" is bound to " . get_debug_type($link) . ', and the entities on the '
                    . "\"{$connection}\" connection need a MysqlLink or PostgresLink.",
                );
            }

            return $link;
        }

        if (isset($built[$connection])) {
            return $built[$connection];
        }

        if ($config->get(Config::scopedKey('DB_CONNECTION', $connection)) === null) {
            throw DatabaseNotConfiguredException::forConnection(OrmFactoryRegistry::class, $connection);
        }

        $link = ConnectionFactory::fromConfig($config, $connection);
        $app->onDispose($link->close(...));

        return $built[$connection] = $link;
    }
}
