<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use RuntimeException;

/**
 * Throws with a message containing a byte sequence that is not valid
 * UTF-8 (a lone 0xC3 followed by a byte that cannot continue it) — for
 * proving ExceptionHandlerMiddleware's development 500 body still
 * encodes as valid JSON rather than throwing JsonException from inside
 * its own catch handler.
 */
final readonly class InvalidUtf8ThrowingController
{
    #[Get('/invalid-utf8-throws')]
    public function boom(): never
    {
        throw new RuntimeException("bad: \xC3\x28 end");
    }
}
