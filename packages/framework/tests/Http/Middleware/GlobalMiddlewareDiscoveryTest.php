<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Middleware;

use Kinetis\Http\Middleware\GlobalMiddlewareDiscovery;
use Kinetis\Tests\Cache\Fixtures\Domain\Orders\UnconventionalMiddleware;
use Kinetis\Tests\Cache\Fixtures\Http\DiscoveredGlobalMiddleware;
use Kinetis\Tests\Cache\Fixtures\Http\DiscoveredMcpMiddleware;
use Kinetis\Tests\Cache\Fixtures\Http\DiscoveredOpenApiAndMcpMiddleware;
use Kinetis\Tests\Cache\Fixtures\Http\HighPriorityMiddleware;
use Kinetis\Tests\Cache\Fixtures\Http\LowPriorityMiddleware;
use PHPUnit\Framework\TestCase;

final class GlobalMiddlewareDiscoveryTest extends TestCase
{
    public function test_discovers_no_middleware_when_the_project_root_does_not_exist(): void
    {
        self::assertSame([], GlobalMiddlewareDiscovery::discover(__DIR__ . '/does-not-exist'));
    }

    public function test_discovers_a_projects_own_middleware_anywhere_under_its_psr4_root(): void
    {
        $classes = GlobalMiddlewareDiscovery::discover(dirname(__DIR__, 2) . '/Cache/Fixtures');

        self::assertContains(DiscoveredGlobalMiddleware::class, $classes);
        self::assertContains(UnconventionalMiddleware::class, $classes);
    }

    public function test_orders_by_priority_descending_with_class_name_as_a_tiebreak(): void
    {
        $classes = GlobalMiddlewareDiscovery::discover(dirname(__DIR__, 2) . '/Cache/Fixtures');

        // Both DiscoveredGlobalMiddleware and UnconventionalMiddleware
        // default to priority 0 — their relative order must come from
        // their own fully-qualified class name, not scan order, since
        // "Domain\Orders\..." sorts before "Http\...".
        self::assertSame(
            [
                HighPriorityMiddleware::class,
                UnconventionalMiddleware::class,
                DiscoveredGlobalMiddleware::class,
                LowPriorityMiddleware::class,
            ],
            $classes,
        );
    }

    public function test_paths_restricts_the_project_wide_scan(): void
    {
        $classes = GlobalMiddlewareDiscovery::discover(dirname(__DIR__, 2) . '/Cache/Fixtures', ['Http']);

        self::assertContains(DiscoveredGlobalMiddleware::class, $classes);
        self::assertNotContains(UnconventionalMiddleware::class, $classes);
    }

    public function test_paths_falls_back_to_the_middleware_discovery_paths_env_var(): void
    {
        putenv('MIDDLEWARE_DISCOVERY_PATHS=Http');

        try {
            $classes = GlobalMiddlewareDiscovery::discover(dirname(__DIR__, 2) . '/Cache/Fixtures');

            self::assertContains(DiscoveredGlobalMiddleware::class, $classes);
            self::assertNotContains(UnconventionalMiddleware::class, $classes);
        } finally {
            putenv('MIDDLEWARE_DISCOVERY_PATHS');
        }
    }

    public function test_an_explicit_paths_argument_wins_over_the_env_var(): void
    {
        putenv('MIDDLEWARE_DISCOVERY_PATHS=DoesNotExist');

        try {
            $classes = GlobalMiddlewareDiscovery::discover(dirname(__DIR__, 2) . '/Cache/Fixtures', []);

            self::assertContains(DiscoveredGlobalMiddleware::class, $classes);
            self::assertContains(UnconventionalMiddleware::class, $classes);
        } finally {
            putenv('MIDDLEWARE_DISCOVERY_PATHS');
        }
    }

    // --- discoverAll(): one shared scan, three independently-sorted lists.

    public function test_discover_all_buckets_by_which_attribute_a_class_carries(): void
    {
        $middleware = GlobalMiddlewareDiscovery::discoverAll(dirname(__DIR__, 2) . '/Cache/Fixtures');

        self::assertContains(DiscoveredGlobalMiddleware::class, $middleware['global']);
        self::assertNotContains(DiscoveredMcpMiddleware::class, $middleware['global']);

        self::assertContains(DiscoveredMcpMiddleware::class, $middleware['mcp']);
        self::assertNotContains(DiscoveredGlobalMiddleware::class, $middleware['mcp']);

        self::assertNotContains(DiscoveredGlobalMiddleware::class, $middleware['openApi']);
    }

    public function test_a_class_carrying_both_mcp_and_openapi_attributes_appears_in_both_lists(): void
    {
        $middleware = GlobalMiddlewareDiscovery::discoverAll(dirname(__DIR__, 2) . '/Cache/Fixtures');

        self::assertContains(DiscoveredOpenApiAndMcpMiddleware::class, $middleware['mcp']);
        self::assertContains(DiscoveredOpenApiAndMcpMiddleware::class, $middleware['openApi']);
    }

    public function test_discover_wraps_discover_all_and_returns_only_the_global_list(): void
    {
        self::assertSame(
            GlobalMiddlewareDiscovery::discoverAll(dirname(__DIR__, 2) . '/Cache/Fixtures')['global'],
            GlobalMiddlewareDiscovery::discover(dirname(__DIR__, 2) . '/Cache/Fixtures'),
        );
    }
}
