<?php

declare(strict_types=1);

namespace Kinetis\Tests\Events;

use Kinetis\Events\EventListenerRegistry;
use Kinetis\Events\Exception\InvalidListenerException;
use Kinetis\Tests\Events\Fixtures\HighPriorityOrderListener;
use Kinetis\Tests\Events\Fixtures\InvalidListenerBuiltinParam;
use Kinetis\Tests\Events\Fixtures\InvalidListenerMultipleParams;
use Kinetis\Tests\Events\Fixtures\InvalidListenerNoParams;
use Kinetis\Tests\Events\Fixtures\InventoryLow;
use Kinetis\Tests\Events\Fixtures\LowPriorityOrderListener;
use Kinetis\Tests\Events\Fixtures\OrderPlaced;
use Kinetis\Tests\Events\Fixtures\RecordingListener;
use Kinetis\Tests\Events\Fixtures\SamePriorityOrderListenerA;
use Kinetis\Tests\Events\Fixtures\SamePriorityOrderListenerB;
use PHPUnit\Framework\TestCase;

final class EventListenerRegistryTest extends TestCase
{
    public function test_register_discovers_every_listener_method_on_a_class(): void
    {
        $registry = new EventListenerRegistry();
        $registry->register(RecordingListener::class);

        self::assertSame(
            [['class' => RecordingListener::class, 'method' => 'onOrderPlaced', 'priority' => 50]],
            $registry->listenersFor(OrderPlaced::class),
        );
        self::assertSame(
            [['class' => RecordingListener::class, 'method' => 'onInventoryLow', 'priority' => 50]],
            $registry->listenersFor(InventoryLow::class),
        );
    }

    public function test_a_method_without_the_listener_attribute_is_ignored(): void
    {
        $registry = new EventListenerRegistry();
        $registry->register(RecordingListener::class);

        $listeners = $registry->listenersFor(OrderPlaced::class);

        self::assertCount(1, $listeners);
        self::assertSame('onOrderPlaced', $listeners[0]['method']);
    }

    public function test_an_event_with_no_registered_listeners_returns_an_empty_list(): void
    {
        $registry = new EventListenerRegistry();

        self::assertSame([], $registry->listenersFor(OrderPlaced::class));
    }

    public function test_register_throws_for_a_listener_with_no_parameters(): void
    {
        $this->expectException(InvalidListenerException::class);

        (new EventListenerRegistry())->register(InvalidListenerNoParams::class);
    }

    public function test_register_throws_for_a_listener_with_a_builtin_typed_parameter(): void
    {
        $this->expectException(InvalidListenerException::class);

        (new EventListenerRegistry())->register(InvalidListenerBuiltinParam::class);
    }

    public function test_register_throws_for_a_listener_with_multiple_parameters(): void
    {
        $this->expectException(InvalidListenerException::class);

        (new EventListenerRegistry())->register(InvalidListenerMultipleParams::class);
    }

    public function test_multiple_listeners_for_the_same_event_are_ordered_by_priority_descending(): void
    {
        $registry = new EventListenerRegistry();
        $registry->register(LowPriorityOrderListener::class);
        $registry->register(HighPriorityOrderListener::class);

        $listeners = $registry->listenersFor(OrderPlaced::class);

        self::assertSame(HighPriorityOrderListener::class, $listeners[0]['class']);
        self::assertSame(LowPriorityOrderListener::class, $listeners[1]['class']);
    }

    public function test_listeners_sharing_a_priority_are_ordered_alphabetically_by_class_name(): void
    {
        // Registered in the "wrong" (Z-then-A) order deliberately — proves
        // the tiebreak comes from sorting, not registration order.
        $registry = new EventListenerRegistry();
        $registry->register(SamePriorityOrderListenerB::class);
        $registry->register(SamePriorityOrderListenerA::class);

        $listeners = $registry->listenersFor(OrderPlaced::class);

        self::assertSame(SamePriorityOrderListenerA::class, $listeners[0]['class']);
        self::assertSame(SamePriorityOrderListenerB::class, $listeners[1]['class']);
    }

    public function test_to_array_from_array_round_trip_preserves_priority_and_order(): void
    {
        $registry = new EventListenerRegistry();
        $registry->register(LowPriorityOrderListener::class);
        $registry->register(HighPriorityOrderListener::class);

        $reconstructed = EventListenerRegistry::fromArray($registry->toArray());

        self::assertSame($registry->listenersFor(OrderPlaced::class), $reconstructed->listenersFor(OrderPlaced::class));
    }
}
