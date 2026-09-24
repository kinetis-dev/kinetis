<?php

declare(strict_types=1);

namespace Kinetis\Http\Routing\Exception;

use RuntimeException;

/**
 * PCRE failed while testing a request path against a route, for example
 * by exhausting the backtrack, recursion or JIT stack limit, and reported
 * an error instead of a match result. The route neither admitted nor
 * rejected the path, so this is a server failure rather than a route
 * miss. The message names the route and PCRE's error, never the request
 * path.
 */
final class RouteMatchingException extends RuntimeException
{
    public static function forRoute(string $pathTemplate, string $pcreError): self
    {
        return new self(sprintf(
            'Route "%s" could not be tested against the request path: %s.',
            $pathTemplate,
            $pcreError,
        ));
    }
}
