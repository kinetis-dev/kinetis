<?php

declare(strict_types=1);

namespace Kinetis\Tests\Fixtures;

use Kinetis\Http\Exception\HttpStatusExceptionInterface;
use RuntimeException;
use Throwable;

/**
 * A broken (or, at the boundaries, exactly-valid) HttpStatusExceptionInterface
 * implementation, for proving ExceptionHandlerMiddleware contains every
 * way this contract can be violated — httpStatus() throwing, or
 * returning something outside the 400-599 range — rather than assuming
 * a well-behaved implementer.
 */
final class ConfigurableHttpStatusException extends RuntimeException implements HttpStatusExceptionInterface
{
    public function __construct(
        string $message,
        private readonly int|Throwable $status,
    ) {
        parent::__construct($message);
    }

    #[\Override]
    public function httpStatus(): int
    {
        if ($this->status instanceof Throwable) {
            throw $this->status;
        }

        return $this->status;
    }
}
