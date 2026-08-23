<?php

declare(strict_types=1);

namespace Kinetis\Logging;

use Psr\Log\AbstractLogger;
use Stringable;
use Throwable;

/**
 * A minimal PSR-3 logger writing through error_log() — the SAPI's own
 * error log, stderr under the CLI. AppScope::boot() binds this as the
 * default LoggerInterface in development (NullLogger stays the production
 * default), so an exception during local development always leaves a
 * trail somewhere without any logging setup at all. A consumer-registered
 * logger wins over this default in every environment.
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
