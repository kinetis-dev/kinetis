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
     * The one message a client sees for a request body that could not be
     * parsed — fixed, and silent about the input, which may be
     * attacker-controlled. An adapter hands its body on raw and
     * `Kinetis\Http\Middleware\RequestBodyMiddleware` answers a parse
     * failure with a 400 carrying this message, so the answer is the
     * same under every runtime; the runtime conformance suite
     * (`Kinetis\Testing\Runtime`) holds each adapter to it. The constant
     * lives here because this interface is the contract the suite drives
     * every adapter through.
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
     * Whether this runtime keeps AppScope warm across requests.
     * {@see HttpStartup} reads it once and hands it to
     * {@see \Kinetis\Http\Kernel}, the only consumer: it gates the
     * `gc_collect_cycles()` that follows every request-scope disposal,
     * including the deferred disposal a streamed response carries on its
     * {@see \Kinetis\Http\StreamScopeLease}.
     */
    public function isPersistent(): bool;
}
