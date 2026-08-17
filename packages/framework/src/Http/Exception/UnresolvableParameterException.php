<?php

declare(strict_types=1);

namespace Kinetis\Http\Exception;

use RuntimeException;
use Throwable;

final class UnresolvableParameterException extends RuntimeException
{
    /**
     * Reached only by a parameter that is untyped or scalar-typed and
     * matched nothing: a class-typed parameter goes to the container
     * instead, and fails with that container's own exception. The
     * message names every source a controller parameter can come from,
     * because the mistake is almost always a missing attribute or a
     * name that does not match the route's placeholder.
     */
    public static function forParameter(string $name): self
    {
        return new self(
            "Cannot resolve controller parameter \"\${$name}\". A parameter is filled from "
            . '#[Body], #[Query], a path placeholder of the same name, the request container '
            . '(class-typed parameters only), or its own default value — this one matched none '
            . 'of them. Add the missing attribute, rename it to match the route placeholder, '
            . 'give it a class type, or give it a default.'
        );
    }

    /**
     * A class-typed parameter the request container had nothing for.
     * Points at the likely cause — the middleware that registers the
     * value is not on this route — rather than at whatever constructor
     * the container's autowiring gave up on, which is what the
     * underlying exception (kept as `previous`) already says.
     */
    public static function forContainerParameter(string $name, string $class, Throwable $previous): self
    {
        return new self(
            "Cannot resolve controller parameter \"\${$name}\" ({$class}) from the request container: "
            . 'nothing registered it, and it could not be constructed. If a middleware is meant to '
            . 'register it, check that middleware is attached to this route; give the parameter a '
            . 'default value if its absence is acceptable.',
            previous: $previous,
        );
    }
}
