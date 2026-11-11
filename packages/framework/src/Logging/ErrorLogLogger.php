<?php

declare(strict_types=1);

namespace Kinetis\Logging;

use Psr\Log\AbstractLogger;
use Stringable;
use Throwable;

/**
 * A minimal PSR-3 logger writing through error_log() — see
 * {@link https://kinetis.dev/docs/logging.html} for where that lands per
 * SAPI. AppScope::boot() binds
 * this as the default LoggerInterface in every environment, so a failure
 * reported only through the logger always leaves a trail without any
 * logging setup. A consumer-registered logger wins over this default.
 *
 * Renders {placeholder} context values into the message per the PSR-3
 * interpolation convention, and appends the class and file:line of a
 * Throwable passed under the `exception` context key — the same key
 * convention real PSR-3 implementations use for stack-trace rendering.
 */
final class ErrorLogLogger extends AbstractLogger
{
    /**
     * @param array<array-key, mixed> $context
     */
    #[\Override]
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $rendered = (string) $message;

        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value instanceof Stringable) {
                $rendered = str_replace('{' . $key . '}', (string) $value, $rendered);
            }
        }

        $exception = $context['exception'] ?? null;

        if ($exception instanceof Throwable) {
            $rendered .= sprintf(' [%s at %s:%d]', $exception::class, $exception->getFile(), $exception->getLine());
        }

        error_log(sprintf('[%s] %s', is_scalar($level) ? (string) $level : 'log', $rendered));
    }
}
