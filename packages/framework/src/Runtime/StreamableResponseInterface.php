<?php

declare(strict_types=1);

namespace Kinetis\Runtime;

use Closure;

/**
 * Opted into by a ResponseInterface that writes its own body directly and
 * incrementally rather than being read via getBody()/getContents(). The
 * contract lives here, not in Kinetis\Http, so Runtime stays the layer that
 * only ever needs to know about PSR-7 plus this one addition; it doesn't
 * need to know about Kinetis\Http\StreamedResponse or any other concrete
 * type that happens to implement it.
 *
 * Whoever ends up holding one of these settles it exactly once, one of
 * two ways: invoke the emitter, which writes the body, or call
 * {@see abandon()}, which writes nothing. Either releases what the
 * response holds for that body, and reaching one after the other
 * releases nothing further.
 *
 * The emitter writes body bytes only. Status, headers, protocol version
 * and reason phrase are read from the response and already sent by the
 * time it is invoked, so it cannot influence them — and a middleware
 * clone that changes any of them carries the same emitter, and the same
 * release, forward.
 */
interface StreamableResponseInterface
{
    public function getEmitter(): Closure;

    /**
     * Releases what this response holds for a body that will never be
     * written, without invoking the emitter.
     *
     * A stream `Kinetis\Http\Kernel` hands back holds that request's own
     * RequestScope open for its emitter to resolve from, so a runtime
     * adapter that cannot stream calls this and settles the request there
     * rather than leaving a live scope behind it. A middleware that
     * answers with a response of its own needs no such call: the Kernel
     * settles the stream it displaced before `handle()` returns. Doing
     * nothing is the correct implementation for a response that holds
     * nothing.
     */
    public function abandon(): void;
}
