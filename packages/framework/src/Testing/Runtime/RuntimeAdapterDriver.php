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
     * A form-encoded request this environment cannot parse — the
     * concrete trigger differs per environment (a body past the SAPI's
     * `post_max_size`, a multipart body with no usable boundary), but
     * the required outcome doesn't: a clean 400, never an uncaught
     * failure.
     */
    public function unparseableFormRequest(): WireRequest;
}
