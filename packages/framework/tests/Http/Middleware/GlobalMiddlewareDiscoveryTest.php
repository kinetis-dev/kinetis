<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Middleware;

use Kinetis\Http\Middleware\GlobalMiddlewareDiscovery;
use Kinetis\Tests\Cache\Fixtures\Domain\Orders\UnconventionalMiddleware;
use Kinetis\Tests\Cache\Fixtures\Http\DiscoveredGlobalMiddleware;
use Kinetis\Tests\Cache\Fixtures\Http\GroupedAdminMiddleware;
use Kinetis\Tests\Cache\Fixtures\Http\GroupedAuditMiddleware;
use Kinetis\Tests\Cache\Fixtures\Http\GroupedAuthMiddleware;
use Kinetis\Tests\Cache\Fixtures\Http\GroupedTracingMiddleware;
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

    // --- discoverAll(): one shared scan, independently-sorted lists.

    public function test_discover_all_buckets_by_which_attribute_a_class_carries(): void
    {
        $middleware = GlobalMiddlewareDiscovery::discoverAll(dirname(__DIR__, 2) . '/Cache/Fixtures');

        self::assertContains(DiscoveredGlobalMiddleware::class, $middleware['global']);
        self::assertNotContains(DiscoveredGlobalMiddleware::class, $middleware['openApi']);
    }

    public function test_discover_wraps_discover_all_and_returns_only_the_global_list(): void
    {
        self::assertSame(
            GlobalMiddlewareDiscovery::discoverAll(dirname(__DIR__, 2) . '/Cache/Fixtures')['global'],
            GlobalMiddlewareDiscovery::discover(dirname(__DIR__, 2) . '/Cache/Fixtures'),
        );
    }

    // --- discoverAll()['groups']: #[AsMiddlewareGroup] membership.

    public function test_discovers_a_named_group_and_its_members(): void
    {
        $groups = GlobalMiddlewareDiscovery::discoverAll(dirname(__DIR__, 2) . '/Cache/Fixtures')['groups'];

        self::assertSame([GroupedAuthMiddleware::class], $groups['auth']);
    }

    public function test_orders_a_groups_members_by_priority_descending(): void
    {
        $groups = GlobalMiddlewareDiscovery::discoverAll(dirname(__DIR__, 2) . '/Cache/Fixtures')['groups'];

        // GroupedAuthMiddleware declares priority 90 in 'admin',
        // GroupedAdminMiddleware 50 — auth runs more outer.
        self::assertSame(
            [GroupedAuthMiddleware::class, GroupedAdminMiddleware::class],
            $groups['admin'],
        );
    }

    public function test_members_sharing_a_priority_are_ordered_alphabetically(): void
    {
        $groups = GlobalMiddlewareDiscovery::discoverAll(dirname(__DIR__, 2) . '/Cache/Fixtures')['groups'];

        self::assertSame(
            [GroupedAuditMiddleware::class, GroupedTracingMiddleware::class],
            $groups['audited'],
        );
    }

    public function test_one_class_can_belong_to_several_groups(): void
    {
        $groups = GlobalMiddlewareDiscovery::discoverAll(dirname(__DIR__, 2) . '/Cache/Fixtures')['groups'];

        self::assertContains(GroupedAuthMiddleware::class, $groups['auth']);
        self::assertContains(GroupedAuthMiddleware::class, $groups['admin']);
    }

    public function test_group_membership_does_not_make_a_class_global_middleware(): void
    {
        $middleware = GlobalMiddlewareDiscovery::discoverAll(dirname(__DIR__, 2) . '/Cache/Fixtures');

        self::assertNotContains(GroupedAuthMiddleware::class, $middleware['global']);
        self::assertNotContains(GroupedAuthMiddleware::class, $middleware['openApi']);
    }

    /**
     * The built-in `openapi` group is always published, empty included:
     * DocumentationController references it unconditionally, and a route
     * naming a group that does not exist is a startup error.
     */
    public function test_only_the_builtin_group_exists_when_the_project_root_does_not_exist(): void
    {
        $groups = GlobalMiddlewareDiscovery::discoverAll(__DIR__ . '/does-not-exist')['groups'];

        self::assertSame([GlobalMiddlewareDiscovery::OPENAPI_GROUP], array_keys($groups));
        self::assertSame([], $groups[GlobalMiddlewareDiscovery::OPENAPI_GROUP]);
    }
}
