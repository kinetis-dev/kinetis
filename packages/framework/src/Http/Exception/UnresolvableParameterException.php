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

    /**
     * A `#[Query]`/path parameter typed the standalone `null` type —
     * genuinely unsatisfiable by any request, ever: a query string or
     * path segment is always a non-empty string when present (never
     * PHP's real `null`, which only a JSON body can carry). For a
     * `#[Query]` parameter this only throws when it's also defaultless —
     * a defaulted one has a real working path, an absent query key,
     * which resolves to the default without ever reaching the type
     * check. A path parameter has no such path regardless of any
     * declared default: a matched route's own placeholder capture always
     * supplies a real string, so the "value missing, use the default"
     * branch is unreachable there — this always throws for a path
     * source. Thrown from `Dispatcher::derivePlan()`, called eagerly from
     * `Kinetis\Http\Routing\Router::register()` itself — the one
     * boundary every route passes through regardless of deployment
     * shape, so a route that can never succeed is rejected before it can
     * ever register, be advertised by OpenApiGenerator, or accept
     * traffic; never deferred to this route's first real dispatch.
     */
    public static function forImpossibleQueryOrPathNull(string $name, string $source): self
    {
        $remedy = $source === 'path'
            ? 'a path placeholder always supplies a real, non-empty string once the route matches at all, so no default could ever be reached here. Change its type, or move it to #[Body], where a real JSON null is representable.'
            : 'omitting it fails as "is required." since it has no default, and providing any value fails the null type check. Give it a default value (so omitting it is the only way to reach it), change its type, or move it to #[Body], where a real JSON null is representable.';

        return new self(
            "Controller parameter \"\${$name}\" is a standalone `null`-typed {$source} parameter — "
            . "this can never be satisfied: a {$source} value is always a non-empty string when present "
            . "(never PHP's real null), and {$remedy}"
        );
    }

    /**
     * An `array`/`iterable`-typed path parameter — genuinely unsatisfiable,
     * unconditionally, unlike a `#[Query]` array (`?tags[]=a&tags[]=b`
     * works there, see {doc}`routing-validation`'s "Query and path
     * values are raw strings" section): a route placeholder is always
     * exactly one path segment, captured by `Route::match()` as a single
     * string — there is no bracket, comma, or any other convention that
     * could ever let a path segment become an array. Thrown from the
     * same `Router::register()`-time boundary as
     * forImpossibleQueryOrPathNull().
     */
    public static function forImpossiblePathArray(string $name): self
    {
        return new self(
            "Controller parameter \"\${$name}\" is an array/iterable-typed path parameter — "
            . 'this can never be satisfied: a path placeholder is always exactly one string segment, '
            . 'never an array, and there is no serialization convention that could make it one. '
            . 'Change its type, move it to #[Query] (where an array-style parameter is representable), '
            . 'or move it to #[Body].'
        );
    }
}
