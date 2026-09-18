<?php

declare(strict_types=1);

namespace Kinetis\Tests\Container;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\Exception\CircularDependencyException;
use Kinetis\Container\Exception\ContainerException;
use Kinetis\Container\Exception\NotFoundException;
use Kinetis\Container\RequestScope;
use Kinetis\Tests\Container\Fixtures\CircularA;
use Kinetis\Tests\Container\Fixtures\Counter;
use Kinetis\Tests\Container\Fixtures\ThrowingDestructor;
use Kinetis\Tests\Container\Fixtures\WithOptionalInterfaceDependency;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RequestScopeTest extends TestCase
{
    private function bootedApp(): AppScope
    {
        $app = new AppScope();
        $app->boot();

        return $app;
    }

    public function test_a_scope_can_resolve_itself(): void
    {
        $scope = $this->bootedApp()->createRequestScope();

        self::assertSame($scope, $scope->get(RequestScope::class));
    }

    public function test_delegates_to_an_explicit_app_binding(): void
    {
        $app = new AppScope();
        $app->bind(Counter::class);
        $app->boot();

        $request = $app->createRequestScope();

        self::assertSame($app->get(Counter::class), $request->get(Counter::class));
    }

    public function test_config_registered_by_boot_delegates_from_a_request_scope_with_no_special_casing(): void
    {
        $app = $this->bootedApp();

        $request = $app->createRequestScope();

        self::assertSame($app->get(Config::class), $request->get(Config::class));
    }

    public function test_two_requests_share_the_same_explicit_app_singleton(): void
    {
        $app = new AppScope();
        $app->bind(Counter::class);
        $app->boot();

        $requestOne = $app->createRequestScope();
        $requestTwo = $app->createRequestScope();

        self::assertSame($requestOne->get(Counter::class), $requestTwo->get(Counter::class));
    }

    public function test_an_unregistered_class_is_not_promoted_to_app_scope(): void
    {
        $app = $this->bootedApp();
        $request = $app->createRequestScope();

        $request->get(Counter::class);

        self::assertFalse(
            $app->has(Counter::class),
            'Resolving an unregistered class through a request scope must not leak it into the persistent app scope.'
        );
    }

    public function test_two_requests_get_different_instances_of_an_unregistered_class(): void
    {
        $app = $this->bootedApp();

        $requestOne = $app->createRequestScope();
        $requestTwo = $app->createRequestScope();

        self::assertNotSame(
            $requestOne->get(Counter::class),
            $requestTwo->get(Counter::class),
            'Each request scope must autowire its own instance, never share one across requests.'
        );
    }

    public function test_an_unregistered_class_is_cached_within_a_single_request(): void
    {
        $app = $this->bootedApp();
        $request = $app->createRequestScope();

        self::assertSame($request->get(Counter::class), $request->get(Counter::class));
    }

    /**
     * Both scopes autowire through Kinetis\Container\Autowire, so an
     * unbound interface is absent here exactly as it is on AppScope.
     */
    public function test_an_unregistered_interface_typed_parameter_falls_back_to_its_default(): void
    {
        $service = $this->bootedApp()->createRequestScope()->get(WithOptionalInterfaceDependency::class);

        self::assertNull($service->thing);
    }

    public function test_dispose_clears_bindings_and_blocks_further_use(): void
    {
        $app = $this->bootedApp();
        $request = $app->createRequestScope();
        $request->bind(Counter::class);

        $request->dispose();

        self::assertTrue($request->isDisposed());
        $this->expectException(ContainerException::class);
        $request->get(Counter::class);
    }

    public function test_get_on_unknown_id_throws_not_found_exception(): void
    {
        $app = $this->bootedApp();
        $request = $app->createRequestScope();

        $this->expectException(NotFoundException::class);
        $request->get('does-not-exist');
    }

    public function test_circular_dependency_is_detected_at_request_scope(): void
    {
        $app = $this->bootedApp();
        $request = $app->createRequestScope();

        $this->expectException(CircularDependencyException::class);
        $request->get(CircularA::class);
    }

    public function test_request_local_binding_overrides_app_binding(): void
    {
        $app = new AppScope();
        $app->bind(Counter::class);
        $app->boot();

        $request = $app->createRequestScope();
        $override = new Counter();
        $request->instance(Counter::class, $override);

        self::assertSame($override, $request->get(Counter::class));
        self::assertNotSame($override, $app->get(Counter::class));
    }

    public function test_dispose_runs_every_registered_callback(): void
    {
        $app = $this->bootedApp();
        $request = $app->createRequestScope();

        $calls = [];
        $request->onDispose(static function () use (&$calls): void {
            $calls[] = 'first';
        });
        $request->onDispose(static function () use (&$calls): void {
            $calls[] = 'second';
        });

        $request->dispose();

        self::assertSame(['first', 'second'], $calls);
    }

    public function test_dispose_still_wipes_state_and_runs_later_callbacks_when_an_earlier_one_throws(): void
    {
        $app = $this->bootedApp();
        $request = $app->createRequestScope();

        $ranSecond = false;
        $request->onDispose(static function (): void {
            throw new RuntimeException('first hook failed');
        });
        $request->onDispose(static function () use (&$ranSecond): void {
            $ranSecond = true;
        });

        try {
            $request->dispose();
            self::fail('Expected a RuntimeException.');
        } catch (RuntimeException $e) {
            self::assertSame('first hook failed', $e->getMessage());
        }

        self::assertTrue($ranSecond, 'A later dispose callback must still run even if an earlier one throws.');
        self::assertTrue($request->isDisposed());
    }

    /**
     * The request-scope half of the same containment
     * {@see \Kinetis\Tests\Container\AppScopeTest} proves for the
     * application scope: a retained instance whose destructor throws is
     * destroyed by the state release itself, and PHP surfaces its
     * exception from that assignment. Without containment the scope
     * would keep its bindings and its callback list, and the next unit
     * of work reusing this object would inherit both.
     */
    public function test_a_throwing_destructor_cannot_stop_the_state_wipe_or_leave_the_scope_usable(): void
    {
        $request = $this->bootedApp()->createRequestScope();
        $request->instance(ThrowingDestructor::class, new ThrowingDestructor('destructor failed'));

        $callbackRuns = 0;
        $request->onDispose(static function () use (&$callbackRuns): void {
            ++$callbackRuns;
        });

        try {
            $request->dispose();
            self::fail('Expected the destructor failure to propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('destructor failed', $e->getMessage());
        }

        self::assertTrue($request->isDisposed(), 'the scope is marked disposed before its state is released, so a destructor cannot leave it live');
        self::assertFalse($request->isRegistered(ThrowingDestructor::class));

        $request->dispose();
        self::assertSame(1, $callbackRuns, 'the callback list was released too, so a second disposal has nothing left to run');

        $this->expectException(ContainerException::class);
        $request->get(Counter::class);
    }

    /**
     * The request-scope half of what
     * {@see \Kinetis\Tests\Container\AppScopeTest} proves for the
     * application scope: the failure a dispose callback reports must not
     * be replaced by the destructor of what that same callback captured.
     * The contained property clear has to own that callable's
     * destruction; the loop variable letting go as dispose() unwinds
     * happens outside every guard.
     */
    public function test_a_failing_dispose_callback_is_not_replaced_by_its_own_captured_destructor(): void
    {
        $request = $this->bootedApp()->createRequestScope();
        $request->onDispose((static function (): callable {
            $held = new ThrowingDestructor('captured destructor');

            return static function () use ($held): void {
                throw new RuntimeException('callback failed');
            };
        })());

        try {
            $request->dispose();
            self::fail('Expected the callback failure to propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('callback failed', $e->getMessage());
        }

        self::assertTrue($request->isDisposed());

        $this->expectException(ContainerException::class);
        $request->get(Counter::class);
    }

    public function test_cannot_register_a_dispose_callback_after_disposal(): void
    {
        $app = $this->bootedApp();
        $request = $app->createRequestScope();
        $request->dispose();

        $this->expectException(ContainerException::class);
        $request->onDispose(static function (): void {});
    }
}
