<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Tests\Fixtures\ConfigurableHttpStatusException;
use RuntimeException;

/**
 * Throws a real HttpStatusExceptionInterface implementation whose own
 * httpStatus() throws — for proving the real Kernel/ExceptionHandlerMiddleware
 * boundary contains this the same way the middleware unit tests do, not
 * only when process() is invoked directly.
 */
final readonly class MalformedHttpStatusThrowingController
{
    #[Get('/malformed-http-status-throws')]
    public function boom(): never
    {
        throw new ConfigurableHttpStatusException(
            'broken exception',
            new RuntimeException('httpStatus() itself failed'),
        );
    }
}
