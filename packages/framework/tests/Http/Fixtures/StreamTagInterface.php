<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

/**
 * A request-scoped value with no autowirable binding of its own — only
 * StreamTagMiddleware registers it, under this id rather than the
 * concrete class, so resolving it inside a streamed emitter proves the
 * emitter reached that request's own scope and not a fresh one.
 */
interface StreamTagInterface
{
    public string $value { get; }
}
