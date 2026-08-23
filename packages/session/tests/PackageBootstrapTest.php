<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Session\Exception\SessionException;
use Kinetis\Session\PackageBootstrap;
use Kinetis\Session\SessionStoreInterface;
use Kinetis\Session\Store\CacheSessionStore;
use Kinetis\Session\Store\FileSessionStore;
use Kinetis\Session\Store\SqlSessionStore;
use Kinetis\Session\Tests\Fixtures\InMemorySessionCache;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

final class PackageBootstrapTest extends TestCase
{
    public function test_no_driver_configured_binds_nothing(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config([]));

        self::assertFalse($app->has(SessionStoreInterface::class));
    }

    public function test_file_driver_binds_a_file_store(): void
    {
        $app = new AppScope();
        $directory = \sys_get_temp_dir() . '/kinetis-bootstrap-test-' . \bin2hex(\random_bytes(6));
        new PackageBootstrap()->register($app, new Config([
            'SESSION_DRIVER' => 'file',
            'SESSION_FILES_DIR' => $directory,
        ]));
        $app->boot();

        self::assertInstanceOf(FileSessionStore::class, $app->get(SessionStoreInterface::class));
        @\rmdir($directory);
    }

    public function test_cache_driver_stores_through_the_bound_cache_interface(): void
    {
        $app = new AppScope();
        $app->instance(CacheInterface::class, new InMemorySessionCache());
        new PackageBootstrap()->register($app, new Config(['SESSION_DRIVER' => 'cache']));
        $app->boot();

        self::assertInstanceOf(CacheSessionStore::class, $app->get(SessionStoreInterface::class));
    }

    public function test_sql_driver_resolves_whatever_link_is_bound(): void
    {
        $app = new AppScope();
        $app->instance(
            'Kinetis\\Persistence\\Contract\\MysqlLink',
            new Fixtures\FakeSessionLink(),
        );
        new PackageBootstrap()->register($app, new Config(['SESSION_DRIVER' => 'sql']));
        $app->boot();

        self::assertInstanceOf(SqlSessionStore::class, $app->get(SessionStoreInterface::class));
    }

    public function test_sql_driver_with_no_link_bound_names_the_fix(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config(['SESSION_DRIVER' => 'sql']));
        $app->boot();

        $this->expectException(SessionException::class);
        $this->expectExceptionMessage('DB_CONNECTION');

        $app->get(SessionStoreInterface::class);
    }

    public function test_an_unknown_driver_throws_naming_the_valid_set(): void
    {
        $this->expectException(SessionException::class);
        $this->expectExceptionMessage('file, cache, sql');

        new PackageBootstrap()->register(new AppScope(), new Config(['SESSION_DRIVER' => 'memcached']));
    }
}
