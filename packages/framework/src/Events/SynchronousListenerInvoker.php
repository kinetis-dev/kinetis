<?php

declare(strict_types=1);

namespace Kinetis\Events;

/**
 * The default ListenerInvokerInterface — calls the listener immediately,
 * inline, exactly as if ShouldQueue had never been checked. Registered
 * automatically by AppScope::boot() unless the consumer already
 * registered their own.
 */
final class SynchronousListenerInvoker implements ListenerInvokerInterface
{
    #[\Override]
    public function invoke(object $listener, string $method, object $event): void
    {
        $listener->{$method}($event);
    }
}
