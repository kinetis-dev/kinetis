<?php

declare(strict_types=1);

namespace Kinetis\Runtime;

/**
 * Whether the client of the request in flight has left, as far as the
 * driving runtime can tell.
 *
 * The Kernel binds the adapter's instance into every request's
 * RequestScope, so a controller, a request-scoped service or a streamed
 * response's emitter injects it like any other dependency. Nothing binds
 * it in AppScope: the answer is about one request, and an app-scoped
 * service has none.
 *
 * {@link https://kinetis.dev/docs/runtime-adapters.html} lists, per
 * runtime, whether a departure is observed and when.
 */
interface ClientConnectionInterface
{
    /**
     * True once this runtime has observed that the client of the current
     * request left. False means not observed, including under a runtime
     * that never observes it. Never resets within a request.
     */
    public function isGone(): bool;
}
