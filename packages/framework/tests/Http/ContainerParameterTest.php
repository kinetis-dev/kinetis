<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http;

use Kinetis\Container\AppScope;
use Kinetis\Container\Exception\CircularDependencyException;
use Kinetis\Container\Exception\ContainerException;
use Kinetis\Container\Exception\NotFoundException;
use Kinetis\Http\Dispatcher;
use Kinetis\Http\Exception\UnresolvableParameterException;
use Kinetis\Http\Routing\Router;
use Kinetis\Tests\Http\Fixtures\AbsentService;
use Kinetis\Tests\Http\Fixtures\BrokenService;
use Kinetis\Tests\Http\Fixtures\ScopedValue;
use Kinetis\Tests\Http\Fixtures\ServiceInjectedController;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * A class-typed controller method parameter is resolved from the request
 * container — the mechanism that lets one controller serve a public
 * route and a middleware-guarded one, since a constructor is shared by
 * every route on its class and a method signature is not.
 */
final class ContainerParameterTest extends TestCase
{
    public function test_resolves_a_value_an_earlier_middleware_registered(): void
    {
        $app = new AppScope();
        $app->boot();
        $scope = $app->createRequestScope();
        $scope->instance(ScopedValue::class, new ScopedValue('registered-by-middleware'));

        $response = new Dispatcher($scope)->dispatch(
            self::router()->match('GET', '/scoped'),
            new ServerRequest('GET', '/scoped'),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"label":"registered-by-middleware"}', (string) $response->getBody());
    }

    /**
     * Nothing can supply the interface and the parameter offers nothing
     * to stand in for it, so the failure surfaces rather than the
     * controller receiving something disconnected.
     */
    public function test_fails_loudly_when_nothing_can_supply_the_parameter(): void
    {
        $app = new AppScope();
        $app->boot();

        $this->expectException(UnresolvableParameterException::class);
        $this->expectExceptionMessageMatches('/Cannot resolve controller parameter .*AbsentService/');

        new Dispatcher($app->createRequestScope())->dispatch(
            self::router()->match('GET', '/absent-required'),
            new ServerRequest('GET', '/absent-required'),
        );
    }

    /**
     * A default answers for an absent dependency: nothing binds the
     * interface, and an interface is not something the container can
     * build on its own.
     */
    public function test_a_default_makes_an_absent_parameter_optional(): void
    {
        $app = new AppScope();
        $app->boot();

        $response = new Dispatcher($app->createRequestScope())->dispatch(
            self::router()->match('GET', '/absent-optional'),
            new ServerRequest('GET', '/absent-optional'),
        );

        self::assertSame('{"label":"absent"}', (string) $response->getBody());
    }

    /**
     * A nullable type with no default written out is optional too.
     */
    public function test_a_nullable_type_alone_makes_an_absent_parameter_optional(): void
    {
        $app = new AppScope();
        $app->boot();

        $response = new Dispatcher($app->createRequestScope())->dispatch(
            self::router()->match('GET', '/absent-nullable'),
            new ServerRequest('GET', '/absent-nullable'),
        );

        self::assertSame('{"label":"absent"}', (string) $response->getBody());
    }

    /**
     * The same default answers for nothing when the dependency is a
     * concrete class the container will attempt and cannot build. The
     * container's own failure reaches the caller unwrapped, naming the
     * class and the parameter it could not supply.
     */
    public function test_a_default_does_not_answer_for_a_class_that_cannot_be_built(): void
    {
        $app = new AppScope();
        $app->boot();

        try {
            new Dispatcher($app->createRequestScope())->dispatch(
                self::router()->match('GET', '/scoped-optional'),
                new ServerRequest('GET', '/scoped-optional'),
            );

            self::fail('Expected the dispatch to fail.');
        } catch (ContainerException $e) {
            self::assertSame(ContainerException::class, $e::class);
            self::assertStringContainsString(ScopedValue::class, $e->getMessage());
            self::assertStringContainsString('$label', $e->getMessage());
        }
    }

    /**
     * A default means "absence is acceptable", not "swallow anything
     * that goes wrong": a dependency cycle is a defect and has to
     * surface even on an optional parameter.
     */
    public function test_a_dependency_cycle_is_not_masked_by_a_default(): void
    {
        $app = new AppScope();
        $app->boot();

        $this->expectException(CircularDependencyException::class);

        new Dispatcher($app->createRequestScope())->dispatch(
            self::router()->match('GET', '/scoped-cyclic'),
            new ServerRequest('GET', '/scoped-cyclic'),
        );
    }

    public function test_a_registered_service_that_fails_to_build_is_not_masked_by_a_default(): void
    {
        $app = new AppScope();
        $app->bind(BrokenService::class, static fn (): BrokenService => new BrokenService());
        $app->boot();

        $this->expectExceptionMessage('service construction failed');

        new Dispatcher($app->createRequestScope())->dispatch(
            self::router()->match('GET', '/scoped-broken'),
            new ServerRequest('GET', '/scoped-broken'),
        );
    }

    /**
     * For an absent dependency the error names the parameter and its
     * type, and keeps the container's own account as `previous`.
     */
    public function test_the_absence_error_points_at_the_parameter(): void
    {
        $app = new AppScope();
        $app->boot();

        try {
            new Dispatcher($app->createRequestScope())->dispatch(
                self::router()->match('GET', '/absent-required'),
                new ServerRequest('GET', '/absent-required'),
            );

            self::fail('Expected the dispatch to fail.');
        } catch (UnresolvableParameterException $e) {
            self::assertStringContainsString('$service', $e->getMessage());
            self::assertStringContainsString(AbsentService::class, $e->getMessage());
            self::assertStringContainsString('middleware is attached to this route', $e->getMessage());
            self::assertInstanceOf(NotFoundException::class, $e->getPrevious());
        }
    }

    public function test_the_container_is_consulted_last_and_shadows_no_other_source(): void
    {
        $app = new AppScope();
        $app->boot();
        $scope = $app->createRequestScope();
        $scope->instance(ScopedValue::class, new ScopedValue('from-scope'));

        $request = new ServerRequest(
            'POST',
            '/scoped/7?sort=name',
            headers: ['Content-Type' => 'application/json'],
            body: json_encode(['name' => 'Alon', 'email' => 'alon@example.com']),
        );

        $response = new Dispatcher($scope)->dispatch(
            self::router()->match('POST', '/scoped/7'),
            $request->withQueryParams(['sort' => 'name']),
        );

        self::assertSame(
            ['id' => 7, 'sort' => 'name', 'user' => 'Alon', 'label' => 'from-scope'],
            json_decode((string) $response->getBody(), true),
        );
    }

    /**
     * The compiled plan carries the same 'container' source the live
     * reflection path derives, so a built cache resolves it identically.
     */
    public function test_the_compiled_plan_resolves_it_the_same_way(): void
    {
        $router = self::router();
        $match = $router->match('GET', '/scoped');

        $plan = Dispatcher::derivePlan(
            new \ReflectionMethod(ServiceInjectedController::class, 'scoped'),
            $match->route,
        );

        self::assertSame('container', $plan[0]['source']);
        self::assertSame(ScopedValue::class, $plan[0]['dtoClass']);

        $app = new AppScope();
        $app->boot();
        $scope = $app->createRequestScope();
        $scope->instance(ScopedValue::class, new ScopedValue('from-compiled-plan'));

        // A sabotaged plan under the real key: if the compiled path were
        // not actually being used, dispatch would fall back to live
        // reflection and still succeed, so this is what proves the plan
        // is consulted at all.
        $sabotaged = $plan;
        $sabotaged[0]['dtoClass'] = null;

        $response = new Dispatcher($scope, [ServiceInjectedController::class . '::scoped' => $plan])
            ->dispatch($match, new ServerRequest('GET', '/scoped'));

        self::assertSame('{"label":"from-compiled-plan"}', (string) $response->getBody());

        $this->expectException(UnresolvableParameterException::class);

        new Dispatcher($scope, [ServiceInjectedController::class . '::scoped' => $sabotaged])
            ->dispatch($match, new ServerRequest('GET', '/scoped'));
    }

    /**
     * The compiled plan carries hasDefault/allowsNull alongside the
     * source, so absence and breakage divide the same way whether the
     * plan came from reflection or from the cache.
     */
    public function test_a_compiled_plan_divides_absence_from_breakage_the_same_way(): void
    {
        $router = self::router();
        $app = new AppScope();
        $app->boot();

        $absent = $router->match('GET', '/absent-optional');
        $absentPlan = Dispatcher::derivePlan(
            new \ReflectionMethod(ServiceInjectedController::class, 'absentOptional'),
            $absent->route,
        );

        $response = new Dispatcher(
            $app->createRequestScope(),
            [ServiceInjectedController::class . '::absentOptional' => $absentPlan],
        )->dispatch($absent, new ServerRequest('GET', '/absent-optional'));

        self::assertSame('{"label":"absent"}', (string) $response->getBody());

        $broken = $router->match('GET', '/scoped-optional');
        $brokenPlan = Dispatcher::derivePlan(
            new \ReflectionMethod(ServiceInjectedController::class, 'optional'),
            $broken->route,
        );

        $this->expectException(ContainerException::class);

        new Dispatcher(
            $app->createRequestScope(),
            [ServiceInjectedController::class . '::optional' => $brokenPlan],
        )->dispatch($broken, new ServerRequest('GET', '/scoped-optional'));
    }

    private static function router(): Router
    {
        $router = new Router();
        $router->register(ServiceInjectedController::class);

        return $router;
    }
}
