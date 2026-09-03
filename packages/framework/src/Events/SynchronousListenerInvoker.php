<?php

declare(strict_types=1);

namespace Kinetis\Events;

use Kinetis\Container\RequestScope;

/**
 * The default ListenerInvokerInterface — resolves the listener from the
 * given scope and calls it immediately, inline, exactly as if ShouldQueue
 * had never been checked. Registered automatically by AppScope::boot()
 * unless the consumer already registered their own.
 */
final class SynchronousListenerInvoker implements ListenerInvokerInterface
{
    #[\Override]
    public function invoke(string $listenerClass, string $method, object $event, RequestScope $scope): void
    {
        $scope->get($listenerClass)->{$method}($event);
    }
}
