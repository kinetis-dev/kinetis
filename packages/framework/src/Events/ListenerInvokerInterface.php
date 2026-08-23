<?php

declare(strict_types=1);

namespace Kinetis\Events;

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
 * $listener and $method are passed separately, not as a single `callable`
 * — a combined `[$listener, $method]` array callable is awkward for an
 * implementation to destructure safely (PHPStan can't narrow a `callable`
 * to a specific `array{object, string}` shape without an unsound cast),
 * and every real implementation needs both pieces separately anyway
 * (QueuedListenerInvoker serializes $listener::class and $method as
 * independent plain strings).
 */
interface ListenerInvokerInterface
{
    public function invoke(object $listener, string $method, object $event): void;
}
