<?php

declare(strict_types=1);

namespace Kinetis\DatabaseBridge\Tests;

use Fiber;
use Kinetis\Cache\DiscoveryContext;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\Exception\ContainerException;
use Kinetis\Container\RequestScope;
use Kinetis\DatabaseBridge\Exception\DatabaseNotConfiguredException;
use Kinetis\DatabaseBridge\OrmMetadata;
use Kinetis\DatabaseBridge\PackageBootstrap;
use Kinetis\DatabaseBridge\Tests\Fixtures\OrmProject\Entities\Post;
use Kinetis\DatabaseBridge\Tests\Fixtures\RowsMysqlLink;
use Kinetis\Orm\EntityManager;
use Kinetis\Orm\EntityManagerRegistry;
use Kinetis\Orm\Exception\CrossFiberAccessException;
use Kinetis\Orm\OrmFactory;
use Kinetis\Orm\OrmFactoryRegistry;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\TransactionGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionProperty;

/**
 * The OrmFactory and request-scoped EntityManager this package's bootstrap
 * binds when kinetis/orm is installed, and what it binds when it is not.
 */
final class OrmWiringTest extends TestCase
{
    private RowsMysqlLink $link;

    protected function setUp(): void
    {
        $this->link = new RowsMysqlLink([['id' => 1, 'title' => 'Hello']]);
    }

    public function test_a_scope_that_never_resolves_the_manager_opens_none_and_registers_no_cleanup(): void
    {
        $scope = $this->app()->createRequestScope();

        self::assertTrue($scope->isRegistered(EntityManager::class));
        self::assertSame([], self::disposeCallbacks($scope));
        $scope->dispose();
    }

    public function test_the_manager_opens_once_per_scope_and_closes_with_it_leaving_the_link_open(): void
    {
        $app = $this->app();
        $scope = $app->createRequestScope();

        $manager = $scope->get(EntityManager::class);

        self::assertInstanceOf(EntityManager::class, $manager);
        self::assertSame($manager, $scope->get(EntityManager::class));
        self::assertCount(1, self::disposeCallbacks($scope));
        self::assertSame($app->get(OrmFactory::class), $app->get(OrmFactory::class));

        $scope->dispose();

        self::assertTrue($manager->isClosed());
        self::assertSame(0, $this->link->closeCalls);
    }

    public function test_the_default_aliases_resolve_the_registries_default_entries(): void
    {
        $app = $this->app();
        $scope = $app->createRequestScope();
        /** @var OrmFactoryRegistry $factories */
        $factories = $app->get(OrmFactoryRegistry::class);
        /** @var EntityManagerRegistry $managers */
        $managers = $scope->get(EntityManagerRegistry::class);

        self::assertSame($factories->factory('default'), $app->get(OrmFactory::class));
        self::assertSame($factories->factoryFor(Post::class), $app->get(OrmFactory::class));
        self::assertSame($managers->manager('default'), $scope->get(EntityManager::class));
        self::assertSame($managers->managerFor(Post::class), $scope->get(EntityManager::class));
        $scope->dispose();
    }

    public function test_sequential_scopes_never_share_a_manager_or_an_identity(): void
    {
        $app = $this->app();

        $first = $app->createRequestScope();
        $firstManager = $first->get(EntityManager::class);
        $firstPost = $firstManager->repository(Post::class)->find(1);
        $first->dispose();

        $second = $app->createRequestScope();
        $secondManager = $second->get(EntityManager::class);
        $secondPost = $secondManager->repository(Post::class)->find(1);

        self::assertNotSame($firstManager, $secondManager);
        self::assertNotSame($firstPost, $secondPost);
        self::assertTrue($firstManager->isClosed());
        self::assertFalse($secondManager->isClosed());
        self::assertCount(2, $this->link->statements);
        $second->dispose();
    }

    public function test_concurrent_scopes_in_separate_fibers_never_share_a_manager_or_an_identity(): void
    {
        $app = $this->app();
        $units = [];

        foreach (['a', 'b'] as $name) {
            $fiber = new Fiber(static function () use ($app, $name, &$units): void {
                $scope = $app->createRequestScope();
                $manager = $scope->get(EntityManager::class);
                $post = $manager->repository(Post::class)->find(1);
                $units[$name] = ['manager' => $manager, 'post' => $post];
                Fiber::suspend();
                self::assertSame($post, $manager->repository(Post::class)->find(1));
                $scope->dispose();
            });
            $fiber->start();
            $fibers[$name] = $fiber;
        }

        self::assertNotSame($units['a']['manager'], $units['b']['manager']);
        self::assertNotSame($units['a']['post'], $units['b']['post']);

        $intruder = new Fiber(static function () use ($units): void {
            $units['a']['manager']->repository(Post::class)->find(1);
        });

        try {
            $intruder->start();
            self::fail('A manager served a Fiber that did not open it.');
        } catch (CrossFiberAccessException) {
        }

        foreach ($fibers as $fiber) {
            $fiber->resume();
            self::assertTrue($fiber->isTerminated());
        }

        self::assertTrue($units['a']['manager']->isClosed());
        self::assertTrue($units['b']['manager']->isClosed());
        self::assertCount(2, $this->link->statements);
    }

    public function test_without_db_connection_both_orm_services_name_the_missing_setting(): void
    {
        $app = $this->app([]);
        $scope = $app->createRequestScope();

        foreach ([
            static fn (): mixed => $app->get(OrmFactory::class),
            static fn (): mixed => $app->get(EntityManager::class),
            static fn (): mixed => $scope->get(EntityManager::class),
        ] as $resolve) {
            try {
                $resolve();
                self::fail('An ORM service resolved without a database.');
            } catch (DatabaseNotConfiguredException $e) {
                self::assertStringContainsString('DB_CONNECTION is not set', $e->getMessage());
            }
        }

        self::assertFalse($app->has(MysqlLink::class));
        self::assertSame([], self::disposeCallbacks($scope));
        self::assertInstanceOf(TransactionGuard::class, $scope->get(TransactionGuard::class));
    }

    /**
     * EntityManager and EntityManagerRegistry are bound per RequestScope
     * only. On AppScope their non-public constructors make autowiring
     * refuse, every time, rather than build a manager that would outlive
     * the request.
     */
    public function test_the_app_scope_never_builds_a_worker_lifetime_manager(): void
    {
        $bridged = $this->app();
        $plain = new AppScope();
        $plain->boot();

        foreach ([
            [$bridged, EntityManager::class],
            [$bridged, EntityManagerRegistry::class],
            [$plain, EntityManager::class],
            [$plain, OrmFactory::class],
        ] as [$app, $id]) {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                try {
                    $app->get($id);
                    self::fail("AppScope built {$id}.");
                } catch (ContainerException $e) {
                    self::assertStringContainsString("Cannot autowire \"{$id}\"", $e->getMessage());
                }
            }
        }

        self::assertFalse($bridged->has(EntityManager::class));
        self::assertFalse($bridged->has(EntityManagerRegistry::class));
    }

    public function test_without_kinetis_orm_no_orm_binding_is_added_and_existing_wiring_holds(): void
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/Fixtures/without-orm.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, $stderr);
        self::assertSame([
            'ormInstalled' => false,
            'compiled' => [],
            'emptyEntryReconstructs' => true,
            'ormEntryRefused' => true,
            'withoutDatabase' => ['factoryBound' => false, 'managerBound' => false, 'linkBound' => false, 'guardResolves' => true],
            'withDatabase' => ['factoryBound' => false, 'managerBound' => false, 'linkBound' => true, 'guardResolves' => true],
        ], json_decode($stdout, true, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * Boots the way the framework does: the OrmMetadata discovery instance
     * is bound before package bootstraps run, and the application's own
     * bootstrap.php binding of the link wins.
     *
     * @param array<string, string> $config
     */
    private function app(array $config = ['DB_CONNECTION' => 'mysql', 'DB_PASSWORD' => 'secret']): AppScope
    {
        $app = new AppScope();
        $app->instance(LoggerInterface::class, new NullLogger());
        $app->instance(OrmMetadata::class, OrmMetadata::fromArray(OrmMetadata::compile(new DiscoveryContext(__DIR__ . '/Fixtures/OrmProject'))));
        new PackageBootstrap()->register($app, new Config($config));

        if ($config !== []) {
            $app->instance(MysqlLink::class, $this->link);
        }

        $app->boot();

        return $app;
    }

    /** @return list<callable(): void> */
    private static function disposeCallbacks(RequestScope $scope): array
    {
        /** @var list<callable(): void> */
        return new ReflectionProperty(RequestScope::class, 'disposeCallbacks')->getValue($scope);
    }
}
