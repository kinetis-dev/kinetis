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
 * The emitter writes body bytes only. Status, headers, protocol version
 * and reason phrase are read from the response and already sent by the
 * time it is invoked, so it cannot influence them — and a middleware
 * clone that changes any of them carries the same emitter forward.
 */
interface StreamableResponseInterface
{
    public function getEmitter(): Closure;
}
