<?php

declare(strict_types=1);

namespace Kinetis\Events;

/**
 * A marker interface (no declared methods) a listener class implements to
 * say "invoke me deferred, not inline." EventDispatcher checks
 * `$listener instanceof ShouldQueue` for each matched listener and, if
 * true, routes invocation through whatever ListenerInvokerInterface is
 * registered instead of calling it directly — core owns both this
 * interface and ListenerInvokerInterface, so a satellite package like
 * kinetis/queue can implement the invoker without core ever depending on
 * it. A ShouldQueue listener with no such package installed still runs,
 * just synchronously — the default SynchronousListenerInvoker, not a
 * hard failure.
 */
interface ShouldQueue
{
}
