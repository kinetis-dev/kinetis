<?php

declare(strict_types=1);

namespace Kinetis\Events;

use Kinetis\Container\RequestScope;

/**
 * The seam a ShouldQueue listener's invocation is routed through instead
 * of being called directly. Core owns this interface and provides
 * SynchronousListenerInvoker as the default (AppScope::boot() registers
 * it automatically, the same "sensible default nobody has to opt into"
 * pattern as LoggerInterface -> NullLogger); a satellite package can
 * implement it to actually defer invocation (kinetis/queue's
 * QueuedListenerInvoker, for one) without core ever depending on that
 * package — the dependency runs satellite-to-core only.
 *
 * $listenerClass is a class-string, not a resolved object — EventDispatcher
 * checks the registry's own `queued` flag *before* ever constructing a
 * listener, so a queued listener's constructor never runs in the process
 * that dispatched the event at all. An implementation that genuinely needs
 * a live instance (SynchronousListenerInvoker, running inline with no
 * deferral) resolves it itself, from the given $scope; one that defers
 * invocation elsewhere (QueuedListenerInvoker) never needs to construct
 * one here, only to name it.
 *
 * $scope is the dispatching request's own RequestScope, the same
 * container EventDispatcher itself resolves non-queued listeners from —
 * handed through so a synchronous implementation can resolve
 * $listenerClass from the exact same scope a direct call would have used,
 * not a disconnected one.
 */
interface ListenerInvokerInterface
{
    public function invoke(string $listenerClass, string $method, object $event, RequestScope $scope): void;
}
