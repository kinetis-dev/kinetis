<?php

declare(strict_types=1);

namespace Kinetis\Tests\Events;

use Kinetis\Events\EventListenerDiscovery;
use Kinetis\Tests\Cache\Fixtures\Domain\Orders\UnconventionalListener;
use Kinetis\Tests\Cache\Fixtures\Events\DiscoveredEvent;
use Kinetis\Tests\Cache\Fixtures\Events\DiscoveredListener;
use Kinetis\Tests\Cache\Fixtures\Events\HighPriorityListener;
use Kinetis\Tests\Cache\Fixtures\Events\LowPriorityListener;
use PHPUnit\Framework\TestCase;

final class EventListenerDiscoveryTest extends TestCase
{
    public function test_discovers_no_listeners_when_the_project_root_does_not_exist(): void
    {
        $registry = EventListenerDiscovery::discover(dirname(__DIR__) . '/Cache/Fixtures/does-not-exist');

        self::assertSame([], $registry->listenersFor(DiscoveredEvent::class));
    }

    public function test_discovers_a_projects_own_listeners_anywhere_under_its_psr4_root(): void
    {
        $registry = EventListenerDiscovery::discover(dirname(__DIR__) . '/Cache/Fixtures');

        $classes = array_column($registry->listenersFor(DiscoveredEvent::class), 'class');

        self::assertContains(DiscoveredListener::class, $classes);
        self::assertContains(UnconventionalListener::class, $classes);
    }

    public function test_orders_by_priority_descending_with_class_name_as_a_tiebreak(): void
    {
        $registry = EventListenerDiscovery::discover(dirname(__DIR__) . '/Cache/Fixtures');

        $classes = array_column($registry->listenersFor(DiscoveredEvent::class), 'class');

        // UnconventionalListener and DiscoveredListener both default to
        // priority 50 — their relative order must come from their own
        // fully-qualified class name, not scan order, since
        // "Domain\Orders\..." sorts before "Events\...".
        self::assertSame(
            [
                HighPriorityListener::class,
                UnconventionalListener::class,
                DiscoveredListener::class,
                LowPriorityListener::class,
            ],
            $classes,
        );
    }

    public function test_paths_restricts_the_project_wide_scan(): void
    {
        $registry = EventListenerDiscovery::discover(dirname(__DIR__) . '/Cache/Fixtures', ['Events']);

        $classes = array_column($registry->listenersFor(DiscoveredEvent::class), 'class');

        self::assertContains(DiscoveredListener::class, $classes);
        self::assertNotContains(UnconventionalListener::class, $classes);
    }

    public function test_paths_falls_back_to_the_listener_discovery_paths_env_var(): void
    {
        putenv('LISTENER_DISCOVERY_PATHS=Events');

        try {
            $registry = EventListenerDiscovery::discover(dirname(__DIR__) . '/Cache/Fixtures');

            $classes = array_column($registry->listenersFor(DiscoveredEvent::class), 'class');

            self::assertContains(DiscoveredListener::class, $classes);
            self::assertNotContains(UnconventionalListener::class, $classes);
        } finally {
            putenv('LISTENER_DISCOVERY_PATHS');
        }
    }

    public function test_an_explicit_paths_argument_wins_over_the_env_var(): void
    {
        putenv('LISTENER_DISCOVERY_PATHS=DoesNotExist');

        try {
            $registry = EventListenerDiscovery::discover(dirname(__DIR__) . '/Cache/Fixtures', []);

            $classes = array_column($registry->listenersFor(DiscoveredEvent::class), 'class');

            self::assertContains(DiscoveredListener::class, $classes);
            self::assertContains(UnconventionalListener::class, $classes);
        } finally {
            putenv('LISTENER_DISCOVERY_PATHS');
        }
    }

    public function test_discovering_against_the_real_kinetis_root_does_not_throw_or_duplicate(): void
    {
        // Same overlap CommandDiscoveryTest's own identical regression
        // test covers: project root and framework root are the same
        // repository when developing Kinetis itself. Nothing under
        // Kinetis\Events carries #[Listener] today, so this can only
        // prove "runs without error" — but it's the exact call that would
        // have silently double-registered before the cross-pass dedup fix.
        $registry = EventListenerDiscovery::discover(dirname(__DIR__, 2));

        self::assertSame([], $registry->listenersFor(DiscoveredEvent::class));
    }
}
