<?php

declare(strict_types=1);

namespace Kinetis\Runtime;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Bridges an external PHP execution environment to the runtime-agnostic
 * Kernel. Implementations own everything environment-specific: how a
 * request arrives (superglobals, an event payload, a runtime-API poll) and
 * how a response leaves (echo + headers, a return payload, ...). The
 * Kernel itself never sees any of that — it only ever consumes and returns
 * PSR-7.
 */
interface RuntimeAdapterInterface
{
    /**
     * The one message a client sees for a request body the environment
     * could not parse — fixed, and silent about the input, which may be
     * attacker-controlled. Every adapter answers a parse failure with a
     * 400 carrying this message; the runtime conformance suite
     * (`Kinetis\Testing\Runtime`) holds them to it.
     */
    public const string MALFORMED_BODY_MESSAGE = 'The request body could not be parsed.';

    /**
     * Start the execution loop (persistent runtimes) or process the single
     * pending request/event (boot-and-die runtimes), invoking $handler once
     * per request with the PSR-7 request that arrived and emitting whatever
     * PSR-7 response it returns back to the environment.
     *
     * @param callable(ServerRequestInterface): ResponseInterface $handler
     */
    public function run(callable $handler): void;

    /**
     * Whether this runtime keeps AppScope warm across requests. Callers use
     * this to decide whether the extra cost of strict state-reset
     * verification is worth paying (persistent) or can be skipped because
     * the process is about to die anyway (boot-and-die).
     */
    public function isPersistent(): bool;
}
