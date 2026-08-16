<?php

declare(strict_types=1);

namespace Kinetis\Events;

use Kinetis\Container\RequestScope;
use Kinetis\Instrumentation\Telemetry;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Never explicitly registered anywhere — autowired fresh per request
 * through RequestScope, the same "TransactionGuard never registered
 * explicitly" pattern. Constructor-inject the concrete EventDispatcher
 * class, not Psr\EventDispatcher\EventDispatcherInterface: interfaces
 * can't be autowired by reflection (there's no way to know which
 * implementation to construct), and registering this class directly on
 * AppScope with a factory that also resolves RequestScope would silently
 * autowire a disconnected RequestScope instead of reaching the real
 * per-request one, the same hazard already documented for
 * JwtAuthMiddleware/BearerAuthMiddleware. Constructor-injecting
 * RequestScope directly here is safe for the identical reason it's safe
 * there: it's always resolved through the request's own scope, never as
 * an AppScope-resolved singleton.
 *
 * $listeners (EventListenerRegistry) and $listenerInvoker
 * (ListenerInvokerInterface) both resolve correctly through RequestScope's
 * existing explicit-AppScope-delegation rule, without any new resolution
 * logic: $listeners because a consumer registers it explicitly
 * (`$app->instance(EventListenerRegistry::class, $registry)`, the same
 * shape as `$app->instance(MysqlConnectionPool::class, ...)`), and
 * $listenerInvoker because AppScope::boot() always registers a default
 * for it, the same as LoggerInterface -> NullLogger.
 */
final readonly class EventDispatcher implements EventDispatcherInterface
{
    public function __construct(
        private RequestScope $scope,
        private EventListenerRegistry $listeners,
        private ListenerInvokerInterface $listenerInvoker,
    ) {}

    #[\Override]
    public function dispatch(object $event): object
    {
        $telemetry = Telemetry::global();
        $eventToken = $telemetry->eventDispatched($event::class);

        try {
            foreach ($this->listeners->listenersFor($event::class) as $listener) {
                if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                    break;
                }

                $instance = $this->scope->get($listener['class']);
                $listenerToken = $telemetry->listenerInvoked($listener['class'], $listener['method']);

                try {
                    if ($instance instanceof ShouldQueue) {
                        $this->listenerInvoker->invoke($instance, $listener['method'], $event);
                    } else {
                        $instance->{$listener['method']}($event);
                    }

                    $telemetry->listenerReturned($listenerToken, null);
                } catch (Throwable $e) {
                    $telemetry->listenerReturned($listenerToken, $e);

                    throw $e;
                }
            }
        } finally {
            $telemetry->eventSettled($eventToken);
        }

        return $event;
    }
}
