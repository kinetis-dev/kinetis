<?php

declare(strict_types=1);

namespace Kinetis\Tests\Instrumentation;

use Kinetis\Container\AppScope;
use Kinetis\Http\Routing\Router;
use Kinetis\Instrumentation\NullTelemetry;
use Kinetis\Instrumentation\Telemetry;
use Kinetis\Instrumentation\TelemetryInterface;
use Kinetis\Testing\TestApplication;
use Kinetis\Tests\Http\Fixtures\UserController;
use PHPUnit\Framework\TestCase;
use Throwable;

use function Kinetis\Async\concurrently;

/**
 * The hooks are exercised through the real request path, not by calling
 * them directly: a recording backend swapped into the global holder
 * observes what a genuine Kernel::handle() emits.
 */
final class TelemetryHooksTest extends TestCase
{
    private RecordingTelemetry $recording;

    #[\Override]
    protected function setUp(): void
    {
        $this->recording = new RecordingTelemetry();
        Telemetry::global()->swap($this->recording);
    }

    #[\Override]
    protected function tearDown(): void
    {
        Telemetry::global()->swap(new NullTelemetry());
    }

    public function test_a_request_emits_route_match_middleware_controller_and_encoding_hooks(): void
    {
        $router = new Router();
        $router->register(UserController::class);
        $app = TestApplication::withRouter($router);

        $response = $app->client()->get('/users', ['limit' => '5']);
        $response->assertOk();

        $calls = array_column($this->recording->calls, 0);
        self::assertContains('routeMatchStarted', $calls);
        self::assertContains('routeMatchEnded', $calls);
        self::assertContains('controllerInvoked', $calls);
        self::assertContains('controllerReturned', $calls);
        self::assertContains('responseEncodingStarted', $calls);
        self::assertContains('responseEncodingEnded', $calls);
        // ExceptionHandlerMiddleware and MaxBodySizeMiddleware are always
        // in the global pipeline, so middleware hooks fire on every
        // request even with none registered explicitly.
        self::assertContains('middlewareEntered', $calls);
    }

    public function test_the_route_match_hook_reports_the_matched_template(): void
    {
        $router = new Router();
        $router->register(UserController::class);
        $app = TestApplication::withRouter($router);

        $app->client()->get('/users/7');

        $ended = $this->recording->firstCall('routeMatchEnded');
        self::assertNotNull($ended);
        self::assertSame('/users/{id}', $ended[1][1]);
    }

    public function test_a_404_reports_the_route_match_ending_without_a_template(): void
    {
        $app = TestApplication::withRouter(new Router());

        $app->client()->get('/nope')->assertStatus(404);

        $ended = $this->recording->firstCall('routeMatchEnded');
        self::assertNotNull($ended);
        self::assertNull($ended[1][1]);
    }

    public function test_hydration_hooks_fire_for_a_body_dto(): void
    {
        $router = new Router();
        $router->register(UserController::class);
        $app = TestApplication::withRouter($router);

        $app->client()->post('/users', ['name' => 'Ada', 'email' => 'ada@example.test']);

        $started = $this->recording->firstCall('hydrationStarted');
        self::assertNotNull($started);
        self::assertStringContainsString('Request', $started[1][0]);
        self::assertContains('hydrationEnded', array_column($this->recording->calls, 0));
    }

    public function test_concurrently_emits_batch_and_per_task_hooks(): void
    {
        concurrently([
            static fn (): int => 1,
            static fn (): int => 2,
        ]);

        $calls = array_column($this->recording->calls, 0);
        self::assertContains('taskBatchStarted', $calls);
        self::assertSame(2, \count(array_keys($calls, 'taskStarted', true)));
        self::assertSame(2, \count(array_keys($calls, 'taskEnded', true)));
        self::assertContains('taskBatchEnded', $calls);
    }

    public function test_a_failing_task_reports_its_failure_and_every_task_still_ends(): void
    {
        try {
            concurrently([
                static fn (): int => 1,
                static fn () => throw new \RuntimeException('task exploded'),
            ]);
            self::fail('Expected the task failure to propagate.');
        } catch (\RuntimeException) {
        }

        $failures = array_filter(
            $this->recording->calls,
            static fn (array $call): bool => $call[0] === 'taskEnded' && $call[1][1] instanceof Throwable,
        );
        self::assertCount(1, $failures);
    }

    public function test_boot_binds_the_global_holder_as_the_interface_default(): void
    {
        $app = new AppScope();
        $app->boot();

        self::assertSame(Telemetry::global(), $app->get(TelemetryInterface::class));
    }

    public function test_a_swapped_backend_is_seen_through_every_holder_reference(): void
    {
        $holder = Telemetry::global();
        $before = new RecordingTelemetry();
        $holder->swap($before);

        $holder->phase('x', 1.0, 2.0);

        self::assertSame([['phase', ['x', 1.0, 2.0]]], $before->calls);
    }
}
