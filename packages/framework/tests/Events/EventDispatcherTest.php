<?php

declare(strict_types=1);

namespace Kinetis\Tests\Events;

use Kinetis\Container\AppScope;
use Kinetis\Events\EventDispatcher;
use Kinetis\Events\EventListenerRegistry;
use Kinetis\Events\ListenerInvokerInterface;
use Kinetis\Tests\Events\Fixtures\ConstructionCountingQueueableListener;
use Kinetis\Tests\Events\Fixtures\InventoryLow;
use Kinetis\Tests\Events\Fixtures\OrderPlaced;
use Kinetis\Tests\Events\Fixtures\QueueableListener;
use Kinetis\Tests\Events\Fixtures\Recorder;
use Kinetis\Tests\Events\Fixtures\RecordingListener;
use Kinetis\Tests\Events\Fixtures\RecordingListenerInvoker;
use Kinetis\Tests\Events\Fixtures\ShouldNeverRunListener;
use Kinetis\Tests\Events\Fixtures\StoppableTestEvent;
use Kinetis\Tests\Events\Fixtures\StoppingListener;
use PHPUnit\Framework\TestCase;

final class EventDispatcherTest extends TestCase
{
    /**
     * @param list<class-string> $listenerClasses
     */
    private function appWithRecorder(array $listenerClasses, Recorder $recorder): AppScope
    {
        $registry = new EventListenerRegistry();

        foreach ($listenerClasses as $listenerClass) {
            $registry->register($listenerClass);
        }

        $app = new AppScope();
        $app->instance(EventListenerRegistry::class, $registry);
        $app->instance(Recorder::class, $recorder);
        $app->boot();

        return $app;
    }

    public function test_dispatch_invokes_a_matching_listener_with_autowired_dependencies(): void
    {
        $recorder = new Recorder();
        $app = $this->appWithRecorder([RecordingListener::class], $recorder);

        $app->createRequestScope()->get(EventDispatcher::class)->dispatch(new OrderPlaced(42));

        self::assertSame(['order:42'], $recorder->messages);
    }

    /**
     * Registering the same class twice — a repeated discovery source, or
     * a caller registering the same class more than once — must invoke
     * each of its listener methods exactly once per matching event, not
     * twice.
     */
    public function test_registering_the_same_listener_class_twice_does_not_double_invoke_it(): void
    {
        $recorder = new Recorder();
        $registry = new EventListenerRegistry();
        $registry->register(RecordingListener::class);
        $registry->register(RecordingListener::class);

        $app = new AppScope();
        $app->instance(EventListenerRegistry::class, $registry);
        $app->instance(Recorder::class, $recorder);
        $app->boot();

        $dispatcher = $app->createRequestScope()->get(EventDispatcher::class);
        $dispatcher->dispatch(new OrderPlaced(1));
        $dispatcher->dispatch(new InventoryLow('SKU-1'));

        self::assertSame(['order:1', 'inventory:SKU-1'], $recorder->messages);
    }

    public function test_dispatch_returns_the_event_it_was_given(): void
    {
        $app = $this->appWithRecorder([], new Recorder());
        $event = new OrderPlaced(1);

        $result = $app->createRequestScope()->get(EventDispatcher::class)->dispatch($event);

        self::assertSame($event, $result);
    }

    public function test_an_event_with_no_registered_listeners_is_a_no_op(): void
    {
        $recorder = new Recorder();
        $app = $this->appWithRecorder([], $recorder);

        $app->createRequestScope()->get(EventDispatcher::class)->dispatch(new OrderPlaced(1));

        self::assertSame([], $recorder->messages);
    }

    public function test_a_stopped_event_does_not_reach_a_later_listener(): void
    {
        $registry = new EventListenerRegistry();
        $registry->register(StoppingListener::class);
        $registry->register(ShouldNeverRunListener::class);

        $app = new AppScope();
        $app->instance(EventListenerRegistry::class, $registry);
        $recorder = new Recorder();
        $app->instance(Recorder::class, $recorder);
        $app->boot();

        $dispatcher = $app->createRequestScope()->get(EventDispatcher::class);
        $dispatcher->dispatch(new StoppableTestEvent());

        self::assertSame([], $recorder->messages);
    }

    public function test_a_shouldqueue_listener_is_routed_through_the_registered_invoker_not_called_directly(): void
    {
        $invoker = new RecordingListenerInvoker();
        $recorder = new Recorder();

        $registry = new EventListenerRegistry();
        $registry->register(QueueableListener::class);

        $app = new AppScope();
        $app->instance(EventListenerRegistry::class, $registry);
        $app->instance(Recorder::class, $recorder);
        $app->instance(ListenerInvokerInterface::class, $invoker);
        $app->boot();

        $dispatcher = $app->createRequestScope()->get(EventDispatcher::class);
        $event = new OrderPlaced(7);
        $dispatcher->dispatch($event);

        self::assertSame([$event], $invoker->invokedWith);
        self::assertSame([], $recorder->messages, 'the listener itself must not run inline');
    }

    public function test_a_shouldqueue_listener_runs_synchronously_via_the_default_invoker_when_none_is_registered(): void
    {
        $recorder = new Recorder();

        $registry = new EventListenerRegistry();
        $registry->register(QueueableListener::class);

        $app = new AppScope();
        $app->instance(EventListenerRegistry::class, $registry);
        $app->instance(Recorder::class, $recorder);
        $app->boot(); // registers the default SynchronousListenerInvoker

        $dispatcher = $app->createRequestScope()->get(EventDispatcher::class);
        $dispatcher->dispatch(new OrderPlaced(9));

        self::assertSame(['queued:9'], $recorder->messages);
    }

    /**
     * The decisive proof this whole design exists for: EventDispatcher
     * must never construct a ShouldQueue listener itself before handing
     * it off to the invoker — only the invoker decides whether/when
     * construction happens. A recording invoker that never resolves
     * anything is what makes the zero-construction outcome observable.
     */
    public function test_a_shouldqueue_listener_is_never_constructed_before_reaching_the_invoker(): void
    {
        ConstructionCountingQueueableListener::$constructions = 0;

        $invoker = new RecordingListenerInvoker();

        $registry = new EventListenerRegistry();
        $registry->register(ConstructionCountingQueueableListener::class);

        $app = new AppScope();
        $app->instance(EventListenerRegistry::class, $registry);
        $app->instance(Recorder::class, new Recorder());
        $app->instance(ListenerInvokerInterface::class, $invoker);
        $app->boot();

        $dispatcher = $app->createRequestScope()->get(EventDispatcher::class);
        $dispatcher->dispatch(new OrderPlaced(1));

        self::assertSame(0, ConstructionCountingQueueableListener::$constructions);
        self::assertSame([ConstructionCountingQueueableListener::class], $invoker->invokedListenerClasses);
    }

    /**
     * The other half of the same proof: the default, no-queue-package
     * SynchronousListenerInvoker still has to actually run the listener
     * — construction is deferred until the invoker itself decides to
     * resolve it, not skipped altogether.
     */
    public function test_the_default_synchronous_invoker_resolves_a_shouldqueue_listener_exactly_once(): void
    {
        ConstructionCountingQueueableListener::$constructions = 0;

        $registry = new EventListenerRegistry();
        $registry->register(ConstructionCountingQueueableListener::class);

        $app = new AppScope();
        $app->instance(EventListenerRegistry::class, $registry);
        $app->boot(); // registers the default SynchronousListenerInvoker

        $dispatcher = $app->createRequestScope()->get(EventDispatcher::class);
        $dispatcher->dispatch(new OrderPlaced(1));

        self::assertSame(1, ConstructionCountingQueueableListener::$constructions);
    }
}
