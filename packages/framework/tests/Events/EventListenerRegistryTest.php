<?php

declare(strict_types=1);

namespace Kinetis\Tests\Events;

use Kinetis\Events\EventListenerRegistry;
use Kinetis\Events\Exception\InvalidListenerException;
use Kinetis\Tests\Events\Fixtures\ConstructionCountingQueueableListener;
use Kinetis\Tests\Events\Fixtures\HighPriorityOrderListener;
use Kinetis\Tests\Events\Fixtures\InvalidListenerBuiltinParam;
use Kinetis\Tests\Events\Fixtures\InvalidListenerMultipleParams;
use Kinetis\Tests\Events\Fixtures\InvalidListenerNoParams;
use Kinetis\Tests\Events\Fixtures\InventoryLow;
use Kinetis\Tests\Events\Fixtures\LowPriorityOrderListener;
use Kinetis\Tests\Events\Fixtures\OrderPlaced;
use Kinetis\Tests\Events\Fixtures\PartiallyInvalidListener;
use Kinetis\Tests\Events\Fixtures\RecordingListener;
use Kinetis\Tests\Events\Fixtures\SamePriorityOrderListenerA;
use Kinetis\Tests\Events\Fixtures\SamePriorityOrderListenerB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EventListenerRegistryTest extends TestCase
{
    public function test_register_discovers_every_listener_method_on_a_class(): void
    {
        $registry = new EventListenerRegistry();
        $registry->register(RecordingListener::class);

        self::assertSame(
            [['class' => RecordingListener::class, 'method' => 'onOrderPlaced', 'priority' => 50, 'queued' => false]],
            $registry->listenersFor(OrderPlaced::class),
        );
        self::assertSame(
            [['class' => RecordingListener::class, 'method' => 'onInventoryLow', 'priority' => 50, 'queued' => false]],
            $registry->listenersFor(InventoryLow::class),
        );
    }

    /**
     * The whole point of tracking this at registration time: a class
     * implementing ShouldQueue is flagged as such in the registry's own
     * plain data, computed once via is_a(), never re-derived from a live
     * instance later.
     */
    public function test_a_shouldqueue_listener_is_flagged_as_queued(): void
    {
        $registry = new EventListenerRegistry();
        $registry->register(ConstructionCountingQueueableListener::class);

        $listeners = $registry->listenersFor(OrderPlaced::class);

        self::assertCount(1, $listeners);
        self::assertTrue($listeners[0]['queued']);
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

    /**
     * The invariant this whole pass exists to establish: a repeated
     * discovery source (or any caller) registering the same class twice
     * must not duplicate its listener entries.
     */
    public function test_registering_the_same_class_twice_is_idempotent(): void
    {
        $registry = new EventListenerRegistry();
        $registry->register(RecordingListener::class);
        $registry->register(RecordingListener::class);

        self::assertCount(1, $registry->listenersFor(OrderPlaced::class));
        self::assertCount(1, $registry->listenersFor(InventoryLow::class));
    }

    /**
     * Atomicity: a class with one valid #[Listener] method and one
     * invalid one must register neither — not the valid one reflected
     * before the failure, not any trace of the class at all. And the
     * failure must not be a one-time thing: a second register() call for
     * the exact same still-invalid class must throw again too, proving
     * the first failed attempt never marked it as already registered
     * (which would make a second call silently return instead).
     */
    public function test_a_partially_invalid_class_registers_none_of_its_methods(): void
    {
        $registry = new EventListenerRegistry();

        try {
            $registry->register(PartiallyInvalidListener::class);
            self::fail('Expected InvalidListenerException on the first attempt.');
        } catch (InvalidListenerException) {
            // expected
        }

        self::assertSame([], $registry->listenersFor(OrderPlaced::class));

        try {
            $registry->register(PartiallyInvalidListener::class);
            self::fail('Expected InvalidListenerException on the second attempt — a silent no-op here would mean the first failure incorrectly marked the class as registered.');
        } catch (InvalidListenerException) {
            // expected
        }

        self::assertSame([], $registry->listenersFor(OrderPlaced::class));

        // A genuinely different class must still register normally
        // afterward — the failed class's own state never leaked into the
        // registry's general bookkeeping.
        $registry->register(RecordingListener::class);
        self::assertCount(1, $registry->listenersFor(OrderPlaced::class));
    }

    /**
     * Kinetis has no deployed generation compiled by a pre-fix version to
     * preserve — a duplicate {class, method} pair in compiled data is
     * corruption, not a legacy shape to tolerate, and is rejected
     * outright rather than silently resolved by keeping one occurrence.
     */
    public function test_from_array_rejects_an_identical_duplicate_class_method_pair(): void
    {
        $entry = ['class' => RecordingListener::class, 'method' => 'onOrderPlaced', 'priority' => 50, 'queued' => false];

        $this->expectException(InvalidListenerException::class);

        EventListenerRegistry::fromArray([OrderPlaced::class => [$entry, $entry]]);
    }

    /**
     * The sharper case: two entries naming the same {class, method} but
     * disagreeing on priority or queued must reject too — there is no
     * principled way to pick a winner, so this must never be resolved by
     * silently keeping whichever one happens to appear first.
     *
     * @return list<array{array<string, mixed>}>
     */
    public static function conflictingDuplicateEntries(): array
    {
        $first = ['class' => RecordingListener::class, 'method' => 'onOrderPlaced', 'priority' => 50, 'queued' => false];

        return [
            'conflicting priority' => [[$first, [...$first, 'priority' => 90]]],
            'conflicting queued' => [[$first, [...$first, 'queued' => true]]],
        ];
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    #[DataProvider('conflictingDuplicateEntries')]
    public function test_from_array_rejects_a_conflicting_duplicate_class_method_pair(array $entries): void
    {
        $this->expectException(InvalidListenerException::class);

        EventListenerRegistry::fromArray([OrderPlaced::class => $entries]);
    }

    /**
     * fromArray()'s own reconstructed $registeredClasses set must match
     * what registering the same data live would have produced — a class
     * already present in compiled data and then also passed to
     * register() (a legitimate, documented pattern: a consumer's own
     * bootstrap adding to an otherwise-compiled registry) must not
     * duplicate it either.
     */
    public function test_from_array_reconstructs_idempotency_for_a_class_already_present(): void
    {
        $registry = EventListenerRegistry::fromArray([
            OrderPlaced::class => [['class' => RecordingListener::class, 'method' => 'onOrderPlaced', 'priority' => 50, 'queued' => false]],
        ]);

        $registry->register(RecordingListener::class);

        self::assertCount(1, $registry->listenersFor(OrderPlaced::class));
    }

    /**
     * A non-string event key (PHP silently coerces a numeric-looking
     * array key to int) is rejected rather than reached as a
     * class-string somewhere downstream.
     */
    public function test_from_array_rejects_a_non_string_event_key(): void
    {
        $this->expectException(InvalidListenerException::class);

        EventListenerRegistry::fromArray([
            0 => [['class' => RecordingListener::class, 'method' => 'onOrderPlaced', 'priority' => 50, 'queued' => false]], // @phpstan-ignore-line
        ]);
    }

    public function test_from_array_rejects_an_event_key_not_shaped_like_a_class_string(): void
    {
        $this->expectException(InvalidListenerException::class);

        EventListenerRegistry::fromArray([
            'not a class name!' => [['class' => RecordingListener::class, 'method' => 'onOrderPlaced', 'priority' => 50, 'queued' => false]],
        ]);
    }

    /**
     * @return list<array{mixed}>
     */
    public static function nonListEntriesValues(): array
    {
        return [
            'a scalar' => ['not-a-list'],
            'an associative array' => [['class' => RecordingListener::class]],
            'a sparse array' => [[0 => 'a', 2 => 'b']],
        ];
    }

    #[DataProvider('nonListEntriesValues')]
    public function test_from_array_rejects_a_non_list_entries_value(mixed $entries): void
    {
        $this->expectException(InvalidListenerException::class);

        EventListenerRegistry::fromArray([OrderPlaced::class => $entries]); // @phpstan-ignore-line
    }

    /**
     * @return list<array{array<string, mixed>}>
     */
    public static function malformedCacheEntries(): array
    {
        $valid = ['class' => RecordingListener::class, 'method' => 'onOrderPlaced', 'priority' => 50, 'queued' => false];

        return [
            'missing class' => [self::without($valid, 'class')],
            'missing method' => [self::without($valid, 'method')],
            'missing priority' => [self::without($valid, 'priority')],
            'missing queued' => [self::without($valid, 'queued')],
            'an extra, unexpected field' => [[...$valid, 'extra' => 'x']],
            'class not a string' => [[...$valid, 'class' => 42]],
            'method not a string' => [[...$valid, 'method' => 42]],
            'priority not an int' => [[...$valid, 'priority' => '50']],
            'queued not a bool' => [[...$valid, 'queued' => 'yes']],
            'class is an empty string' => [[...$valid, 'class' => '']],
            'method is an empty string' => [[...$valid, 'method' => '']],
            'class not shaped like a class-string' => [[...$valid, 'class' => 'not a class!']],
            'method not shaped like an identifier' => [[...$valid, 'method' => 'not an identifier!']],
        ];
    }

    /**
     * @param array<string, mixed> $without
     */
    private static function without(array $without, string $key): array
    {
        unset($without[$key]);

        return $without;
    }

    /**
     * A format-version match alone doesn't rule out a genuinely corrupt
     * or hand-edited entry — this must throw loudly rather than reach
     * EventDispatcher as a silent undefined-array-key.
     *
     * @param array<string, mixed> $entry
     */
    #[DataProvider('malformedCacheEntries')]
    public function test_from_array_rejects_a_malformed_entry(array $entry): void
    {
        $this->expectException(InvalidListenerException::class);

        EventListenerRegistry::fromArray([OrderPlaced::class => [$entry]]);
    }

    /**
     * fromArray() never trusts the input's own order — every event's
     * list is re-sorted by the identical priority-desc/class/method
     * comparator register() itself uses.
     */
    public function test_from_array_canonicalizes_order_regardless_of_input_order(): void
    {
        $low = ['class' => LowPriorityOrderListener::class, 'method' => 'onOrderPlaced', 'priority' => 50, 'queued' => false];
        $high = ['class' => HighPriorityOrderListener::class, 'method' => 'onOrderPlaced', 'priority' => 90, 'queued' => false];

        // Deliberately given out of priority order.
        $registry = EventListenerRegistry::fromArray([OrderPlaced::class => [$low, $high]]);

        $listeners = $registry->listenersFor(OrderPlaced::class);

        self::assertSame(HighPriorityOrderListener::class, $listeners[0]['class']);
        self::assertSame(LowPriorityOrderListener::class, $listeners[1]['class']);
    }
}
