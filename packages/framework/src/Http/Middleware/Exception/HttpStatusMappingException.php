<?php

declare(strict_types=1);

namespace Kinetis\Http\Middleware\Exception;

use Kinetis\Http\Exception\HttpStatusExceptionInterface;
use RuntimeException;
use Throwable;

/**
 * A broken `HttpStatusExceptionInterface` implementation — `httpStatus()`
 * itself threw, or returned a value outside the 400-599 range the
 * interface requires. Never returned to a client directly: this is the
 * context `ExceptionHandlerMiddleware` logs alongside the original
 * exception when it falls back to a generic 500, via `getPrevious()` when
 * a real secondary `Throwable` caused it.
 */
final class HttpStatusMappingException extends RuntimeException
{
    public static function invalidStatus(HttpStatusExceptionInterface $original, int $status): self
    {
        return new self(sprintf(
            '%s::httpStatus() returned %d — HttpStatusExceptionInterface requires a value in the 400-599 range.',
            $original::class,
            $status,
        ));
    }

    public static function threw(HttpStatusExceptionInterface $original, Throwable $cause): self
    {
        return new self(
            sprintf('%s failed to map to a response: %s', $original::class, $cause->getMessage()),
            previous: $cause,
        );
    }
}
