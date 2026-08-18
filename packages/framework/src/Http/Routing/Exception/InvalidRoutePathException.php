<?php

declare(strict_types=1);

namespace Kinetis\Http\Routing\Exception;

use RuntimeException;

/**
 * A declared path is not absolute. Route paths are always rooted, so
 * anything not starting with `/` is a typo rather than a shorthand —
 * including the empty string, which would otherwise resolve to `/` and
 * quietly claim the root route.
 */
final class InvalidRoutePathException extends RuntimeException
{
    public static function forRoute(string $class, string $method, string $path): self
    {
        return new self(sprintf(
            '"%s::%s()" declares the path "%s" — a route path must start with "/". Use "/" itself for the root, or for the path a #[RoutePrefix] already names.',
            $class,
            $method,
            $path,
        ));
    }

    public static function forPrefix(string $class, string $prefix): self
    {
        return new self(sprintf(
            '#[RoutePrefix("%s")] on "%s" must start with "/".',
            $prefix,
            $class,
        ));
    }
}
