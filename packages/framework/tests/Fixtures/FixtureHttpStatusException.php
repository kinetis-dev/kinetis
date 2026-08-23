<?php

declare(strict_types=1);

namespace Kinetis\Tests\Fixtures;

use Kinetis\Http\Exception\HttpStatusExceptionInterface;
use RuntimeException;

/**
 * Stands in for a satellite package's own exception — the shape
 * ExceptionHandlerMiddlewareTest/KernelTest exercise the general
 * HttpStatusExceptionInterface mechanism against, independent of any one
 * real implementer.
 */
final class FixtureHttpStatusException extends RuntimeException implements HttpStatusExceptionInterface
{
    #[\Override]
    public function httpStatus(): int
    {
        return 400;
    }
}
