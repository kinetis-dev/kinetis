<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http;

use Kinetis\Http\CallableRequestHandler;
use Kinetis\Http\MiddlewarePipeline;
use Kinetis\Tests\Http\Fixtures\ClassLevelMiddleware;
use Kinetis\Tests\Http\Fixtures\MethodLevelMiddleware;
use Kinetis\Tests\Http\Fixtures\RecordingMiddleware;
use Kinetis\Tests\Http\Fixtures\ShortCircuitMiddleware;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class MiddlewarePipelineTest extends TestCase
{
    protected function setUp(): void
    {
        RecordingMiddleware::$log = [];
    }

    public function test_with_no_middleware_the_core_handler_runs_directly(): void
    {
        $pipeline = new MiddlewarePipeline(
            [],
            new CallableRequestHandler(static fn () => new Response(200)),
        );

        $response = $pipeline->handle(new ServerRequest('GET', '/'));

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_middleware_run_in_list_order_before_the_core_handler(): void
    {
        $pipeline = new MiddlewarePipeline(
            [new ClassLevelMiddleware(), new MethodLevelMiddleware()],
            new CallableRequestHandler(static fn () => new Response(200)),
        );

        $pipeline->handle(new ServerRequest('GET', '/'));

        self::assertSame(
            [ClassLevelMiddleware::class, MethodLevelMiddleware::class],
            RecordingMiddleware::$log,
        );
    }

    public function test_a_middleware_can_short_circuit_before_the_core_handler_runs(): void
    {
        $reached = false;

        $pipeline = new MiddlewarePipeline(
            [new ShortCircuitMiddleware(), new ClassLevelMiddleware()],
            new CallableRequestHandler(static function () use (&$reached) {
                $reached = true;

                return new Response(200);
            }),
        );

        $response = $pipeline->handle(new ServerRequest('GET', '/'));

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($reached);
        self::assertSame([], RecordingMiddleware::$log);
    }
}
