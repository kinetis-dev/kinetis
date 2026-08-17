<?php

declare(strict_types=1);

namespace Kinetis\Session;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\PackageBootstrapInterface;
use Kinetis\Session\Exception\SessionException;
use Kinetis\Session\Store\CacheSessionStore;
use Kinetis\Session\Store\FileSessionStore;
use Kinetis\Session\Store\SqlSessionStore;
use Psr\Container\ContainerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Declared via `extra.kinetis`: with `SESSION_DRIVER` set, binds
 * {@see SessionStoreInterface} so {@see Middleware\SessionMiddleware}
 * autowires with nothing else to register. Unset means inert — the same
 * convention as kinetis/persistence's own bootstrap and DB_CONNECTION.
 *
 * The bindings are factories, resolved on first use rather than here:
 * the `cache` driver needs the CacheInterface binding AppScope::boot()
 * registers, and the `sql` driver needs whatever link
 * kinetis/persistence's bootstrap binds — both of which may not exist
 * yet at package-bootstrap time, since neither boot() nor sibling
 * bootstraps are guaranteed to have run first. By first *use*, they all
 * have.
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
            'cache' => static function (ContainerInterface $container): CacheSessionStore {
                /** @var CacheInterface $cache */
                $cache = $container->get(CacheInterface::class);

                return new CacheSessionStore($cache);
            },
            'sql' => self::sqlFactory(...),
            default => throw new SessionException(
                "Unknown SESSION_DRIVER \"{$driver}\" — valid values: file, cache, sql.",
            ),
        };

        $app->bind(SessionStoreInterface::class, $factory);
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
