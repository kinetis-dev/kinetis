<?php

declare(strict_types=1);

namespace Kinetis\Http\Exception;

use Kinetis\Validation\Hydrator;
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
     * A class-typed parameter nothing can supply, on a signature that
     * offers no default and no nullable type to stand in for it. Points
     * at the likely cause — the middleware that registers the value is
     * not on this route — rather than at the container's own vocabulary,
     * which the underlying exception (kept as `previous`) already
     * carries.
     */
    public static function forContainerParameter(string $name, string $class, Throwable $previous): self
    {
        return new self(
            "Cannot resolve controller parameter \"\${$name}\" ({$class}) from the request container: "
            . 'nothing registered it, and it is not something the container can build on its own. '
            . 'If a middleware is meant to register it, check that middleware is attached to this '
            . 'route; give the parameter a default value, or a nullable type, if its absence is '
            . 'acceptable.',
            previous: $previous,
        );
    }

    /**
     * A `#[Query]`/path parameter declaring a builtin type outside
     * `Kinetis\Validation\Hydrator::SUPPORTED_BUILTIN_TYPES`. A query
     * string or path segment carries text only, so `null`, `true`,
     * `false`, `object` and `callable` have no value a request could
     * ever send. Thrown from `Dispatcher::derivePlan()`, called eagerly
     * from `Kinetis\Http\Routing\Router::register()` — the one boundary
     * every route passes through regardless of deployment shape, so such
     * a route is rejected before it can register, be advertised by
     * OpenApiGenerator, or accept traffic.
     */
    public static function forUnsupportedBuiltinType(string $name, string $source, string $type): self
    {
        return new self(
            "Controller parameter \"\${$name}\" is a {$source} parameter typed \"{$type}\" — no {$source} "
            . 'value can satisfy that type. Kinetis binds ' . implode(', ', Hydrator::SUPPORTED_BUILTIN_TYPES)
            . ' from a query or path value; move the parameter to #[Body] if it needs another type.'
        );
    }

    /**
     * An `array`/`iterable`-typed path parameter — genuinely unsatisfiable,
     * unconditionally, unlike a `#[Query]` array (`?tags=a&tags=b` works
     * there, see {doc}`routing-validation`'s "Query and path values are
     * raw strings" section): a route placeholder is always exactly one
     * path segment, captured by `Route::match()` as a single string —
     * there is no repetition, comma, or any other convention that could
     * ever let a path segment become an array. Thrown from the same
     * `Router::register()`-time boundary as
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
