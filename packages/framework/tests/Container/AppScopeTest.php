<?php

declare(strict_types=1);

namespace Kinetis\Tests\Container;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\Exception\CircularDependencyException;
use Kinetis\Container\Exception\ContainerException;
use Kinetis\Container\Exception\NotFoundException;
use Kinetis\Container\RequestScope;
use Kinetis\Logging\ErrorLogLogger;
use Kinetis\Runtime\AppEnvironment;
use Kinetis\Tests\Container\Fixtures\CircularA;
use Kinetis\Tests\Container\Fixtures\Counter;
use Kinetis\Tests\Container\Fixtures\ServiceA;
use Kinetis\Tests\Container\Fixtures\ServiceB;
use Kinetis\Tests\Container\Fixtures\Unresolvable;
use Kinetis\Tests\Container\Fixtures\WithDefault;
use Kinetis\Tests\Container\Fixtures\WithOptionalInterfaceDependency;
use Kinetis\Tests\Container\Fixtures\WithOptionalUnresolvableDependency;
use Kinetis\Tests\Container\Fixtures\WithRequiredUnresolvableDependency;
use Kinetis\SimpleCache\Exception\SimpleCacheUnavailableException;
use Kinetis\SimpleCache\NullSimpleCache;
use Kinetis\SimpleCache\UnavailableSimpleCache;
use Kinetis\Tests\Http\Fixtures\GlobalMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

final class AppScopeTest extends TestCase
{
    public function test_autowires_a_class_with_no_explicit_binding(): void
    {
        $app = new AppScope();

        $counter = $app->get(Counter::class);

        self::assertInstanceOf(Counter::class, $counter);
    }

    public function test_an_unregistered_class_is_never_promoted_to_a_shared_singleton(): void
    {
        $app = new AppScope();
        $app->boot();

        // Autowired because the class exists, but a fresh instance every
        // call — only an explicit registration creates a shared singleton,
        // so per-request state can't leak across requests through a stray
        // get() on the app scope.
        self::assertNotSame($app->get(Counter::class), $app->get(Counter::class));
    }

    public function test_shared_binding_returns_the_same_instance(): void
    {
        $app = new AppScope();
        $app->bind(Counter::class);

        self::assertSame($app->get(Counter::class), $app->get(Counter::class));
    }

    public function test_non_shared_binding_returns_a_new_instance_each_time(): void
    {
        $app = new AppScope();
        $app->bind(Counter::class, shared: false);

        self::assertNotSame($app->get(Counter::class), $app->get(Counter::class));
    }

    public function test_instance_binding_returns_the_exact_given_object(): void
    {
        $app = new AppScope();
        $counter = new Counter();

        $app->instance(Counter::class, $counter);

        self::assertSame($counter, $app->get(Counter::class));
    }

    public function test_autowiring_resolves_nested_dependencies(): void
    {
        $app = new AppScope();

        $serviceB = $app->get(ServiceB::class);

        self::assertInstanceOf(ServiceA::class, $serviceB->serviceA);
        self::assertSame(1, $serviceB->serviceA->counter->count);
    }

    public function test_autowiring_uses_default_value_when_no_binding_exists(): void
    {
        $app = new AppScope();

        $service = $app->get(WithDefault::class);

        self::assertSame(10, $service->limit);
    }

    public function test_binding_after_boot_throws(): void
    {
        $app = new AppScope();
        $app->boot();

        $this->expectException(ContainerException::class);
        $app->bind(Counter::class);
    }

    public function test_instance_registration_after_boot_throws(): void
    {
        $app = new AppScope();
        $app->boot();

        $this->expectException(ContainerException::class);
        $app->instance(Counter::class, new Counter());
    }

    public function test_get_on_unknown_id_throws_not_found_exception(): void
    {
        $app = new AppScope();

        $this->expectException(NotFoundException::class);
        $app->get('does-not-exist');
    }

    public function test_circular_dependency_is_detected(): void
    {
        $app = new AppScope();

        $this->expectException(CircularDependencyException::class);
        $app->get(CircularA::class);
    }

    public function test_unresolvable_constructor_parameter_throws(): void
    {
        $app = new AppScope();

        $this->expectException(ContainerException::class);
        $app->get(Unresolvable::class);
    }

    // --- AppScope resolves itself. A class/interface-typed parameter's
    // own default value is honored the same way a builtin-typed one's
    // already is. ---

    public function test_app_scope_resolves_to_the_exact_same_instance_after_boot(): void
    {
        $app = new AppScope();
        $app->boot();

        self::assertSame($app, $app->get(AppScope::class));
    }

    public function test_an_unregistered_interface_typed_parameter_falls_back_to_its_default(): void
    {
        $app = new AppScope();

        $service = $app->get(WithOptionalInterfaceDependency::class);

        self::assertNull($service->thing);
    }

    public function test_a_concrete_class_typed_parameter_that_fails_to_autowire_falls_back_to_its_default(): void
    {
        $app = new AppScope();

        $service = $app->get(WithOptionalUnresolvableDependency::class);

        self::assertNull($service->addr);
    }

    public function test_a_required_unresolvable_class_typed_parameter_still_throws(): void
    {
        $app = new AppScope();

        $this->expectException(ContainerException::class);
        $app->get(WithRequiredUnresolvableDependency::class);
    }

    public function test_creating_a_request_scope_before_boot_throws(): void
    {
        $app = new AppScope();

        $this->expectException(ContainerException::class);
        $app->createRequestScope();
    }

    public function test_creating_a_request_scope_after_boot_succeeds(): void
    {
        $app = new AppScope();
        $app->boot();

        self::assertInstanceOf(RequestScope::class, $app->createRequestScope());
    }

    public function test_middlewares_returns_registered_classes_in_registration_order(): void
    {
        $app = new AppScope();
        $app->middleware(GlobalMiddleware::class);

        self::assertSame([GlobalMiddleware::class], $app->middlewares());
    }

    public function test_middlewares_is_empty_when_nothing_was_registered(): void
    {
        $app = new AppScope();

        self::assertSame([], $app->middlewares());
    }

    public function test_registering_middleware_after_boot_throws(): void
    {
        $app = new AppScope();
        $app->boot();

        $this->expectException(ContainerException::class);
        $app->middleware(GlobalMiddleware::class);
    }

    public function test_boot_registers_a_null_logger_by_default(): void
    {
        $app = new AppScope();
        $app->boot();

        self::assertInstanceOf(NullLogger::class, $app->get(LoggerInterface::class));
    }

    public function test_boot_does_not_override_a_consumer_registered_logger(): void
    {
        $app = new AppScope();
        $logger = new NullLogger();
        $app->instance(LoggerInterface::class, $logger);
        $app->boot();

        self::assertSame($logger, $app->get(LoggerInterface::class));
    }

    public function test_boot_registers_the_detected_app_environment(): void
    {
        $app = new AppScope();
        $app->boot();

        self::assertInstanceOf(AppEnvironment::class, $app->get(AppEnvironment::class));
    }

    public function test_boot_defaults_the_logger_to_error_log_in_development(): void
    {
        $app = new AppScope();
        $app->instance(AppEnvironment::class, AppEnvironment::Development);
        $app->boot();

        self::assertInstanceOf(ErrorLogLogger::class, $app->get(LoggerInterface::class));
    }

    public function test_a_consumer_registered_logger_wins_over_the_development_default(): void
    {
        $app = new AppScope();
        $logger = new NullLogger();
        $app->instance(AppEnvironment::class, AppEnvironment::Development);
        $app->instance(LoggerInterface::class, $logger);
        $app->boot();

        self::assertSame($logger, $app->get(LoggerInterface::class));
    }

    public function test_boot_registers_a_config_snapshot_by_default(): void
    {
        $app = new AppScope();
        $app->boot();

        self::assertInstanceOf(Config::class, $app->get(Config::class));
    }

    public function test_boot_does_not_override_a_consumer_registered_config(): void
    {
        $app = new AppScope();
        $config = new Config(['DB_HOST' => 'custom.internal']);
        $app->instance(Config::class, $config);
        $app->boot();

        self::assertSame($config, $app->get(Config::class));
    }

    public function test_boot_registers_a_null_simple_cache_by_default_when_redis_is_not_configured(): void
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config([]));
        $app->boot();

        self::assertInstanceOf(NullSimpleCache::class, $app->get(CacheInterface::class));
    }

    public function test_boot_succeeds_when_redis_is_configured_but_the_driver_package_is_not_installed(): void
    {
        // RedisSimpleCache/ClusteredRedisSimpleCache live in the separate
        // kinetis/cache-redis package, never installed for core's own test
        // suite — this is the real, always-true "not installed" branch,
        // not a simulated one. Booting survives it: an application that
        // never touches the cache runs unaffected by a stale REDIS_HOST.
        $app = new AppScope();
        $app->instance(Config::class, new Config(['REDIS_HOST' => 'localhost']));
        $app->boot();

        self::assertInstanceOf(UnavailableSimpleCache::class, $app->get(CacheInterface::class));
    }

    public function test_boot_succeeds_when_redis_cluster_is_configured_but_the_driver_package_is_not_installed(): void
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config(['REDIS_CLUSTER' => 'true', 'REDIS_CLUSTER_SEEDS' => 'node1:7001']));
        $app->boot();

        self::assertInstanceOf(UnavailableSimpleCache::class, $app->get(CacheInterface::class));
    }

    public function test_using_the_cache_without_the_driver_package_throws_naming_the_package(): void
    {
        // The other half: deferring the failure must not mean losing it.
        $app = new AppScope();
        $app->instance(Config::class, new Config(['REDIS_HOST' => 'localhost']));
        $app->boot();

        $cache = $app->get(CacheInterface::class);
        self::assertInstanceOf(CacheInterface::class, $cache);

        $this->expectException(SimpleCacheUnavailableException::class);
        $this->expectExceptionMessage('kinetis/cache-redis');
        $cache->get('anything');
    }

    /**
     * Every operation throws — a partially-working cache would be worse
     * than either failing outright or not existing.
     *
     * @return iterable<string, array{callable(CacheInterface): mixed}>
     */
    public static function cacheOperations(): iterable
    {
        yield 'get' => [static fn (CacheInterface $c): mixed => $c->get('k')];
        yield 'set' => [static fn (CacheInterface $c): mixed => $c->set('k', 'v')];
        yield 'delete' => [static fn (CacheInterface $c): mixed => $c->delete('k')];
        yield 'clear' => [static fn (CacheInterface $c): mixed => $c->clear()];
        yield 'has' => [static fn (CacheInterface $c): mixed => $c->has('k')];
        yield 'getMultiple' => [static fn (CacheInterface $c): mixed => $c->getMultiple(['k'])];
        yield 'setMultiple' => [static fn (CacheInterface $c): mixed => $c->setMultiple(['k' => 'v'])];
        yield 'deleteMultiple' => [static fn (CacheInterface $c): mixed => $c->deleteMultiple(['k'])];
    }

    /**
     * @param callable(CacheInterface): mixed $operation
     */
    #[DataProvider('cacheOperations')]
    public function test_every_unavailable_cache_operation_throws(callable $operation): void
    {
        $this->expectException(SimpleCacheUnavailableException::class);
        $operation(new UnavailableSimpleCache());
    }

    public function test_boot_does_not_override_a_consumer_registered_cache(): void
    {
        $app = new AppScope();
        $cache = new NullSimpleCache();
        $app->instance(CacheInterface::class, $cache);
        $app->boot();

        self::assertSame($cache, $app->get(CacheInterface::class));
    }
}
