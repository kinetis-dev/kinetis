<?php

declare(strict_types=1);

namespace Kinetis\Logging;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Logs through a consumer-supplied `LoggerInterface` without letting a
 * failure in the logger itself escape — for the handful of call sites
 * that are themselves a terminal fallback (ExceptionHandlerMiddleware's
 * catch-all, DocumentationController's cache-failure recovery): a
 * logging call there is diagnostic, not load-bearing, and a throwing
 * logger must never turn an observability problem into the very failure
 * the surrounding code exists to recover from. Ordinary logging
 * elsewhere in the framework goes through `$logger->error(...)`/
 * `warning(...)` directly, exactly as PSR-3 intends — this exists only
 * for the boundary itself.
 */
final class SafeLogger
{
    /**
     * @param array<string, mixed> $context
     */
    public static function log(LoggerInterface $logger, string $level, string $message, array $context = []): void
    {
        try {
            $logger->log($level, $message, $context);
        } catch (Throwable) {
            // Discarded deliberately — see this class's own docblock.
        }
    }

    /**
     * Like log(), but also covers resolving the logger itself — not just
     * its own log() call. `SafeLogger::log($container->get(LoggerInterface::class),
     * ...)` is **not** actually safe on its own: PHP evaluates that
     * argument expression before log() is ever entered, so a throwing
     * container resolution (a broken logger binding/factory) escapes
     * completely uncaught, outside this class's own try/catch. Passing
     * the resolution itself as a callable closes that gap — both the
     * resolver and the resolved logger's log() call are covered by the
     * same containment.
     *
     * @param callable(): LoggerInterface $resolveLogger
     * @param array<string, mixed> $context
     */
    public static function logFrom(callable $resolveLogger, string $level, string $message, array $context = []): void
    {
        try {
            $resolveLogger()->log($level, $message, $context);
        } catch (Throwable) {
            // Discarded deliberately — see this class's own docblock.
        }
    }
}
