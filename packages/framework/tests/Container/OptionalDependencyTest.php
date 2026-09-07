<?php

declare(strict_types=1);

namespace Kinetis\Tests\Container;

use Kinetis\Container\AppScope;
use Kinetis\Container\Autowire;
use Kinetis\Container\Exception\CircularDependencyException;
use Kinetis\Container\Exception\ContainerException;
use Kinetis\Container\Exception\DisconnectedRequestScopeException;
use Kinetis\Container\Exception\NotFoundException;
use Kinetis\Tests\Container\Fixtures\CircularA;
use Kinetis\Tests\Container\Fixtures\CircularB;
use Kinetis\Tests\Container\Fixtures\Counter;
use Kinetis\Tests\Container\Fixtures\ExternalPsrContainer;
use Kinetis\Tests\Container\Fixtures\OptionalInterface;
use Kinetis\Tests\Container\Fixtures\Unresolvable;
use Kinetis\Tests\Container\Fixtures\WithNullableInterfaceDependency;
use Kinetis\Tests\Container\Fixtures\WithOptionalAbstractDependency;
use Kinetis\Tests\Container\Fixtures\WithOptionalCircularDependency;
use Kinetis\Tests\Container\Fixtures\WithOptionalConcreteDependency;
use Kinetis\Tests\Container\Fixtures\WithOptionalInterfaceDependency;
use Kinetis\Tests\Container\Fixtures\WithOptionalRequestScopeDependency;
use Kinetis\Tests\Container\Fixtures\WithOptionalUnresolvableDependency;
use Kinetis\Tests\Container\Fixtures\WithRequiredInterfaceDependency;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * A constructor parameter's default value, or its nullable type, says the
 * dependency may be absent. It never says a broken one is acceptable.
 *
 * Both scopes answer identically, because both autowire through
 * Kinetis\Container\Autowire and both draw the absent/broken line with
 * Kinetis\Container\ResolutionAvailability.
 */
final class OptionalDependencyTest extends TestCase
{
    /**
     * The container each check runs against: the application scope
     * itself, and a request scope over it. Bindings are registered on the
     * AppScope before the factory runs, so the request scope inherits
     * them the same way a real request does.
     *
     * @return iterable<string, array{callable(AppScope): ContainerInterface}>
     */
    public static function scopes(): iterable
    {
        yield 'app scope' => [static fn (AppScope $app): ContainerInterface => $app];
        yield 'request scope' => [static function (AppScope $app): ContainerInterface {
            $app->boot();

            return $app->createRequestScope();
        }];
    }

    // --- Absence: the default, or null, stands in. ---

    /**
     * @param callable(AppScope): ContainerInterface $scope
     */
    #[DataProvider('scopes')]
    public function test_an_unbound_interface_uses_the_declared_default(callable $scope): void
    {
        $service = $scope(new AppScope())->get(WithOptionalInterfaceDependency::class);

        self::assertInstanceOf(WithOptionalInterfaceDependency::class, $service);
        self::assertNull($service->thing);
    }

    /**
     * A nullable type with no default written out is optional too: null
     * is the only value absence could produce.
     *
     * @param callable(AppScope): ContainerInterface $scope
     */
    #[DataProvider('scopes')]
    public function test_an_unbound_interface_on_a_nullable_parameter_without_a_default_uses_null(callable $scope): void
    {
        $service = $scope(new AppScope())->get(WithNullableInterfaceDependency::class);

        self::assertInstanceOf(WithNullableInterfaceDependency::class, $service);
        self::assertNull($service->thing);
    }

    /**
     * An interface is not the only type nothing can build unbound; a
     * class that exists but is not instantiable answers the same way.
     *
     * @param callable(AppScope): ContainerInterface $scope
     */
    #[DataProvider('scopes')]
    public function test_an_unbound_abstract_class_uses_the_default(callable $scope): void
    {
        $service = $scope(new AppScope())->get(WithOptionalAbstractDependency::class);

        self::assertInstanceOf(WithOptionalAbstractDependency::class, $service);
        self::assertNull($service->thing);
    }

    /**
     * Absence with nothing to stand in for it stays the container's own
     * failure, naming the id nobody bound.
     *
     * @param callable(AppScope): ContainerInterface $scope
     */
    #[DataProvider('scopes')]
    public function test_a_required_unbound_interface_reports_the_containers_own_absence_failure(callable $scope): void
    {
        try {
            $scope(new AppScope())->get(WithRequiredInterfaceDependency::class);

            self::fail('Expected resolution to fail.');
        } catch (NotFoundException $e) {
            self::assertSame(
                'No entry was found for identifier "' . OptionalInterface::class . '".',
                $e->getMessage(),
            );
        }
    }

    // --- Broken: the dependency exists and cannot be supplied. ---

    /**
     * The dependency is a concrete class, so it is available and it is
     * built — and its own required string parameter is what fails. The
     * optional parameter's default covers absence, not this.
     *
     * @param callable(AppScope): ContainerInterface $scope
     */
    #[DataProvider('scopes')]
    public function test_an_optional_dependency_whose_own_dependency_cannot_be_built_propagates(callable $scope): void
    {
        try {
            $scope(new AppScope())->get(WithOptionalUnresolvableDependency::class);

            self::fail('Expected resolution to fail.');
        } catch (ContainerException $e) {
            self::assertSame(ContainerException::class, $e::class);
            self::assertStringContainsString(Unresolvable::class, $e->getMessage());
            self::assertStringContainsString('$name', $e->getMessage());
        }
    }

    /**
     * @param callable(AppScope): ContainerInterface $scope
     */
    #[DataProvider('scopes')]
    public function test_a_bound_factory_that_throws_propagates_through_an_optional_parameter(callable $scope): void
    {
        $app = new AppScope();
        $app->bind(
            OptionalInterface::class,
            static fn (): OptionalInterface => throw new RuntimeException('backend offline'),
        );

        $container = $scope($app);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('backend offline');

        $container->get(WithOptionalInterfaceDependency::class);
    }

    /**
     * @param callable(AppScope): ContainerInterface $scope
     */
    #[DataProvider('scopes')]
    public function test_a_cycle_reached_through_an_optional_parameter_propagates_with_its_path(callable $scope): void
    {
        try {
            $scope(new AppScope())->get(WithOptionalCircularDependency::class);

            self::fail('Expected resolution to fail.');
        } catch (CircularDependencyException $e) {
            self::assertSame(
                'Circular dependency detected: '
                . WithOptionalCircularDependency::class . ' -> '
                . CircularA::class . ' -> '
                . CircularB::class . ' -> '
                . CircularA::class,
                $e->getMessage(),
            );
        }
    }

    // --- Scope rules outrank any default. ---

    public function test_an_optional_request_scope_dependency_resolved_from_the_app_scope_propagates(): void
    {
        $app = new AppScope();
        $app->boot();

        try {
            $app->get(WithOptionalRequestScopeDependency::class);

            self::fail('Expected resolution to fail.');
        } catch (DisconnectedRequestScopeException $e) {
            self::assertStringContainsString(WithOptionalRequestScopeDependency::class, $e->getMessage());
        }
    }

    /**
     * A disposed scope resolves nothing, so it reports its own lifecycle
     * failure rather than answering "absent" and letting a default be
     * manufactured out of a container that is gone.
     */
    public function test_a_disposed_request_scope_fails_instead_of_supplying_a_default(): void
    {
        $app = new AppScope();
        $app->boot();
        $scope = $app->createRequestScope();
        $scope->dispose();

        try {
            Autowire::instantiate(WithOptionalInterfaceDependency::class, $scope);

            self::fail('Expected autowiring to fail.');
        } catch (ContainerException $e) {
            self::assertSame('This request scope has already been disposed and cannot be reused.', $e->getMessage());
        }

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('This request scope has already been disposed and cannot be reused.');

        $scope->get(Counter::class);
    }

    // --- A container from outside Kinetis is asked only has(). ---

    /**
     * has() reporting false is the whole answer, even for a class that
     * exists and would autowire in a Kinetis scope: an external container
     * owns what it can supply.
     */
    public function test_an_external_container_reporting_absence_supplies_the_default(): void
    {
        $container = new ExternalPsrContainer(
            false,
            static fn (string $id): mixed => throw new RuntimeException("resolved {$id}"),
        );

        $service = Autowire::instantiate(WithOptionalConcreteDependency::class, $container);

        self::assertNull($service->counter);
        self::assertSame(0, $container->getCalls);
        self::assertSame(1, $container->hasCalls);
    }

    /**
     * has() reporting true makes every get() failure the dependency's
     * own, propagated untouched.
     */
    public function test_an_external_container_that_has_the_id_propagates_its_failure(): void
    {
        $container = new ExternalPsrContainer(
            true,
            static fn (): mixed => throw new RuntimeException('external resolution failed'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('external resolution failed');

        Autowire::instantiate(WithOptionalInterfaceDependency::class, $container);
    }
}
