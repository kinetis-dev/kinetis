<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http;

use Kinetis\Container\AppScope;
use Kinetis\Container\Exception\CircularDependencyException;
use Kinetis\Container\Exception\ContainerException;
use Kinetis\Http\Dispatcher;
use Kinetis\Http\Exception\UnresolvableParameterException;
use Kinetis\Http\Routing\Router;
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
     * Nothing registered it and it cannot be autowired, so the failure
     * surfaces rather than the controller receiving something
     * disconnected.
     */
    public function test_fails_loudly_when_nothing_registered_the_value(): void
    {
        $app = new AppScope();
        $app->boot();

        $this->expectException(UnresolvableParameterException::class);
        $this->expectExceptionMessageMatches('/Cannot resolve controller parameter .*ScopedValue/');

        new Dispatcher($app->createRequestScope())->dispatch(
            self::router()->match('GET', '/scoped'),
            new ServerRequest('GET', '/scoped'),
        );
    }

    public function test_a_default_makes_the_parameter_optional(): void
    {
        $app = new AppScope();
        $app->boot();

        $response = new Dispatcher($app->createRequestScope())->dispatch(
            self::router()->match('GET', '/scoped-optional'),
            new ServerRequest('GET', '/scoped-optional'),
        );

        self::assertSame('{"label":"absent"}', (string) $response->getBody());
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
     * Without a default, the error names the parameter and its type
     * rather than whatever constructor autowiring gave up on — the
     * original is kept as `previous`.
     */
    public function test_the_error_points_at_the_parameter_not_at_autowiring(): void
    {
        $app = new AppScope();
        $app->boot();

        try {
            new Dispatcher($app->createRequestScope())->dispatch(
                self::router()->match('GET', '/scoped'),
                new ServerRequest('GET', '/scoped'),
            );

            self::fail('Expected the dispatch to fail.');
        } catch (UnresolvableParameterException $e) {
            self::assertStringContainsString('$value', $e->getMessage());
            self::assertStringContainsString(ScopedValue::class, $e->getMessage());
            self::assertStringContainsString('middleware is attached to this route', $e->getMessage());
            self::assertInstanceOf(ContainerException::class, $e->getPrevious());
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
            body: json_encode(['name' => 'Alon', 'email' => 'alon@noy.cc']),
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

    private static function router(): Router
    {
        $router = new Router();
        $router->register(ServiceInjectedController::class);

        return $router;
    }
}
