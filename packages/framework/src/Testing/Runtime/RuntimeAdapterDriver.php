<?php

declare(strict_types=1);

namespace Kinetis\Testing\Runtime;

/**
 * What an adapter has to provide to run under
 * {@see RuntimeAdapterConformanceTestCase}: a way to push one wire
 * request through its own environment and report what came out, plus
 * the few facts the environment decides on the adapter's behalf.
 *
 * Those facts are declared here rather than chosen by the test so every
 * behavior is asserted on every adapter — a driver says what its
 * environment does, and the suite checks the adapter honors it. Nothing
 * is skipped.
 */
interface RuntimeAdapterDriver
{
    /**
     * Run $request through the adapter, answering with $response from the
     * handler, and report what the handler saw and what the environment
     * received.
     */
    public function dispatch(WireRequest $request, ResponseSpec $response): Outcome;

    /**
     * The client address this environment reports to the handler as
     * `REMOTE_ADDR` — a real socket's peer for a SAPI, whatever the
     * driver injects as `sourceIp` for Lambda. The test can't choose it,
     * so the driver states it.
     */
    public function expectedClientIp(): string;

    /**
     * Whether a {@see \Kinetis\Runtime\StreamableResponseInterface} can
     * reach the client incrementally here. Either answer is asserted: a
     * streaming environment must deliver the chunks, a non-streaming one
     * must reject the response rather than silently buffer or drop it.
     */
    public function supportsStreaming(): bool;

    /**
     * The URI scheme this environment serves over when the request
     * carries no forwarded scheme of its own — `http` for a SAPI or a
     * worker behind a plain listener, `https` for an API Gateway
     * integration that has no plaintext mode at all. What a forwarded
     * scheme does to it is asserted separately, against
     * {@see trustsTheConnectingClient()} and
     * {@see supportsPlaintextRequests()}.
     */
    public function expectedScheme(): string;

    /**
     * Whether a purely-numeric header name (`123`, a valid RFC 9110
     * token) survives into the PSR-7 request. Both answers are
     * asserted: an environment that keeps it must deliver the value
     * unchanged, one that cannot must drop the header outright rather
     * than deliver it under some other name or with some other value.
     */
    public function preservesNumericHeaderNames(): bool;

    /**
     * Whether the order a client sent its cookies in survives. Both
     * answers are asserted: the names and values must arrive intact
     * either way, and an environment that declares order-preserving has
     * to deliver them in the original order too.
     */
    public function preservesCookieOrder(): bool;

    /**
     * Whether this environment treats the peer the driver connects from
     * as a trusted edge — one whose `X-Forwarded-Proto` may decide the
     * request's scheme.
     *
     * Both answers are asserted. A driver that says yes has to deliver a
     * forwarded scheme; one that says no has to ignore the header
     * entirely and serve the scheme its environment actually serves,
     * because on a directly reachable listener that header is the
     * client's own to set. Declared rather than assumed for the same
     * reason every other fact here is: this is the environment's
     * decision, and an adapter that quietly disagrees with it is what the
     * suite exists to catch.
     */
    public function trustsTheConnectingClient(): bool;

    /**
     * Whether a plaintext request can reach this environment at all. A
     * SAPI or a worker behind a plain listener serves one; an API
     * Gateway integration cannot, because it terminates TLS itself and
     * has no plaintext listener for such a request to have arrived on.
     *
     * Both answers are asserted, on the one input that separates them —
     * an `X-Forwarded-Proto` naming `http`. An environment that can
     * serve plaintext settles that header under the trust rule
     * {@see trustsTheConnectingClient()} states. One that cannot has
     * neither to honor nor to ignore it: the header describes a request
     * that cannot have reached it, and the request is refused before the
     * handler. Declared here rather than read off `expectedScheme()`,
     * which says what the environment serves and not what it can
     * receive.
     */
    public function supportsPlaintextRequests(): bool;
}
