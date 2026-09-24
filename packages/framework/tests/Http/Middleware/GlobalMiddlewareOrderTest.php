<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Middleware;

use Kinetis\Http\Middleware\CorsMiddleware;
use Kinetis\Http\Middleware\ExceptionHandlerMiddleware;
use Kinetis\Http\Middleware\GlobalMiddlewareOrder;
use Kinetis\Http\Middleware\RequestBodyMiddleware;
use Kinetis\Http\Middleware\SecurityHeadersMiddleware;
use Kinetis\Tests\Http\Fixtures\ClassLevelMiddleware;
use Kinetis\Tests\Http\Fixtures\DiscoveredGlobalMiddleware;
use Kinetis\Tests\Http\Fixtures\GlobalMiddleware;
use PHPUnit\Framework\TestCase;

final class GlobalMiddlewareOrderTest extends TestCase
{
    public function test_explicit_then_discovered_follow_the_three_fixed_classes(): void
    {
        self::assertSame(
            [
                SecurityHeadersMiddleware::class,
                ExceptionHandlerMiddleware::class,
                RequestBodyMiddleware::class,
                GlobalMiddleware::class,
                ClassLevelMiddleware::class,
                DiscoveredGlobalMiddleware::class,
            ],
            GlobalMiddlewareOrder::resolve(
                [GlobalMiddleware::class, ClassLevelMiddleware::class],
                [ClassLevelMiddleware::class, DiscoveredGlobalMiddleware::class],
            ),
        );
    }

    /**
     * Registered CORS runs once, outside the exception boundary and the
     * body stage, and every other class keeps its relative order.
     */
    public function test_registered_cors_is_hoisted_once_outside_the_exception_boundary(): void
    {
        self::assertSame(
            [
                SecurityHeadersMiddleware::class,
                CorsMiddleware::class,
                ExceptionHandlerMiddleware::class,
                RequestBodyMiddleware::class,
                GlobalMiddleware::class,
                ClassLevelMiddleware::class,
                DiscoveredGlobalMiddleware::class,
            ],
            GlobalMiddlewareOrder::resolve(
                [GlobalMiddleware::class, CorsMiddleware::class, ClassLevelMiddleware::class, CorsMiddleware::class],
                [DiscoveredGlobalMiddleware::class],
            ),
        );
    }
}
