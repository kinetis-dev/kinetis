<?php

declare(strict_types=1);

namespace Kinetis\Session;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\PackageBootstrapInterface;
use Kinetis\Session\Exception\SessionException;
use Kinetis\Session\Store\FileSessionStore;
use Kinetis\Session\Store\RedisSessionStore;
use Kinetis\Session\Store\SqlSessionStore;
use Kinetis\SimpleCache\RedisSimpleCache;
use Psr\Container\ContainerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Declared via `extra.kinetis`: with `SESSION_DRIVER` set, binds
 * {@see SessionStoreInterface} so {@see Middleware\SessionMiddleware}
 * autowires with nothing else to register. Unset means inert — the same
 * convention as kinetis/persistence's own bootstrap and DB_CONNECTION.
 *
 * The bindings are factories, resolved on first use rather than here:
 * the `redis` driver consumes the CacheInterface binding
 * AppScope::boot() registers, and the `sql` driver the link
 * kinetis/persistence's bootstrap binds — neither of which need exist
 * yet at package-bootstrap time, since boot() and sibling bootstraps are
 * not guaranteed to have run first. By first *use*, they have.
 */
final readonly class PackageBootstrap implements PackageBootstrapInterface
{
    private const string MYSQL_LINK = 'Kinetis\\Persistence\\Contract\\MysqlLink';

    private const string POSTGRES_LINK = 'Kinetis\\Persistence\\Contract\\PostgresLink';

    #[\Override]
    public function register(AppScope $app, Config $config): void
    {
        $driver = $config->string('SESSION_DRIVER', '');

        if ($driver === '') {
            return;
        }

        $factory = match ($driver) {
            'file' => static fn (): FileSessionStore => new FileSessionStore(
                $config->string('SESSION_FILES_DIR', \sys_get_temp_dir() . '/kinetis-sessions'),
            ),
            'redis' => self::redisFactory(...),
            'sql' => self::sqlFactory(...),
            default => throw new SessionException(
                "Unknown SESSION_DRIVER \"{$driver}\" — valid values: file, redis, sql.",
            ),
        };

        $app->bind(SessionStoreInterface::class, $factory);
    }

    /**
     * Takes the application's own cache binding — AppScope::boot()
     * resolves RedisSimpleCache when Redis is configured, and a consumer
     * that bound its own wins — and requires it to be the concrete
     * optional class, since update() needs its conditional replace().
     */
    private static function redisFactory(ContainerInterface $container): RedisSessionStore
    {
        if (!\class_exists(RedisSimpleCache::class)) {
            throw new SessionException(
                'SESSION_DRIVER=redis needs kinetis/cache-redis: composer require kinetis/cache-redis.',
            );
        }

        $cache = $container->get(CacheInterface::class);

        if (!$cache instanceof RedisSimpleCache) {
            throw new SessionException(
                'SESSION_DRIVER=redis found no Redis cache binding — configure Redis (REDIS_URL, REDIS_HOST, '
                . 'or REDIS_CLUSTER with REDIS_CLUSTER_SEEDS) so AppScope::boot() binds RedisSimpleCache, or '
                . 'bind one in bootstrap.php.',
            );
        }

        return new RedisSessionStore($cache);
    }

    private static function sqlFactory(ContainerInterface $container): SqlSessionStore
    {
        if (!\interface_exists(self::MYSQL_LINK)) {
            throw new SessionException(
                'SESSION_DRIVER=sql needs kinetis/persistence: composer require kinetis/persistence.',
            );
        }

        foreach ([self::MYSQL_LINK, self::POSTGRES_LINK] as $contract) {
            if ($container->has($contract)) {
                /** @var \Kinetis\Persistence\Contract\SqlLink $link */
                $link = $container->get($contract);

                return new SqlSessionStore($link);
            }
        }

        throw new SessionException(
            'SESSION_DRIVER=sql found no database binding — set DB_CONNECTION so kinetis/persistence '
            . 'binds a connection, or bind a link in bootstrap.php.',
        );
    }
}
