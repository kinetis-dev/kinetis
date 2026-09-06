<?php

declare(strict_types=1);

namespace Kinetis\Http;

use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\Logging\SafeLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

/**
 * The single owner of one streamed response's RequestScope, released
 * exactly once by whichever of Kernel's paths reaches it first: the
 * wrapper's emitter finishing, the wrapper being abandoned, or the next
 * request finding this one still pending.
 *
 * release() contains and logs a disposal failure instead of raising it:
 * every path that reaches it has already settled what the client gets —
 * an emitted stream's status, headers and part of its body are on the
 * wire, and an abandoned one's replacement is the adapter's or the
 * middleware's own response — so there is nothing left to turn into the
 * generic 500 a buffered response's disposal failure legitimately
 * becomes ({@see Kernel::disposeScope()}). A failure raised by the
 * emitter itself stays primary.
 *
 * The destructor is prompt cleanup for a wrapper that is simply dropped,
 * not the isolation guarantee — a fatal bailout skips it, and an
 * exception trace or a retaining logger can hold the last reference past
 * the request that created it. An owner that knows the body will never
 * be written says so instead, through
 * {@see \Kinetis\Runtime\StreamableResponseInterface::abandon()}; what
 * neither of those covers, Kernel releases at the top of the next
 * `handle()`, before that request runs anything of its own.
 *
 * @internal
 */
final class StreamScopeLease
{
    private bool $released = false;

    public function __construct(
        private readonly AppScope $app,
        private readonly RequestScope $scope,
        public readonly string $method,
        public readonly string $path,
    ) {}

    public function isReleased(): bool
    {
        return $this->released;
    }

    /**
     * Disposes the scope on the first call and does nothing on every
     * later one. Never throws, so every caller — including a `finally`
     * around a failing emitter, and this class's own destructor — can
     * run it unconditionally.
     */
    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;

        try {
            $this->scope->dispose();
        } catch (Throwable $disposeFailure) {
            // logFrom(), not log(): resolving LoggerInterface is part of
            // what must not escape here, since this scope is already
            // disposed and a throwing binding on AppScope would leave
            // the emitter's own `finally` raising a cleanup failure over
            // a stream that is already partly delivered.
            SafeLogger::logFrom(
                fn (): LoggerInterface => $this->app->get(LoggerInterface::class),
                LogLevel::ERROR,
                'Request scope disposal failed after streaming {method} {path}: {message}',
                [
                    'method' => $this->method,
                    'path' => $this->path,
                    'message' => $disposeFailure->getMessage(),
                    'exception' => $disposeFailure,
                ],
            );
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
