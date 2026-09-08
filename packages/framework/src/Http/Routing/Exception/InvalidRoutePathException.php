<?php

declare(strict_types=1);

namespace Kinetis\Http\Routing\Exception;

use RuntimeException;

/**
 * A declared path can't be turned into a working route: it isn't
 * absolute, it carries a control character, or its `{...}` placeholder
 * syntax is malformed. Every case fails at registration, where the
 * mistake is, rather than as a silent permanent 404 on the route's first
 * real request.
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

    /**
     * A path or prefix containing a control character (including a NUL
     * byte) — never a real attribute-literal value, but a real risk once
     * either can come from a compiled cache artifact. Left unrejected,
     * a NUL byte or other control character would compile into a real
     * PCRE pattern that matches on unexpected byte sequences rather than
     * failing loudly.
     */
    public static function forControlCharacters(string $path): self
    {
        return new self(sprintf(
            'A route path or prefix must not contain control characters: "%s".',
            addcslashes($path, "\x00..\x1f\x7f"),
        ));
    }

    public static function duplicatePlaceholderName(string $pathTemplate, string $name): self
    {
        return new self(sprintf(
            'Route "%s" declares the placeholder "{%s}" more than once — every placeholder in one path template needs a distinct name, since two identically-named capture groups can\'t compile into one working regex.',
            $pathTemplate,
            $name,
        ));
    }

    /**
     * Two placeholders written back to back, with no literal text
     * separating them. Each matches any run of characters up to the next
     * `/`, so where one ends and the next begins is undecidable and the
     * request segment would be split at an arbitrary point.
     */
    public static function adjacentPlaceholders(string $pathTemplate, string $first, string $second): self
    {
        return new self(sprintf(
            'Route "%s" declares "{%s}{%s}" — two placeholders with nothing between them have no boundary, so a segment matching both can only be split arbitrarily. Separate them with literal text, or capture the whole segment as one placeholder.',
            $pathTemplate,
            $first,
            $second,
        ));
    }

    /**
     * A `{...}` expression that isn't a plain placeholder name. Routing
     * describes URL structure only, so there is no inline syntax for
     * constraining what a placeholder matches — the value's own shape is
     * described where the value is consumed.
     */
    public static function malformedPlaceholder(string $pathTemplate, string $expression): self
    {
        return new self(sprintf(
            'Route "%s" declares "%s", which is not a placeholder — a placeholder is "{name}", where name is a plain identifier. Constrain what a path value may hold with the controller parameter\'s own type and validation attributes.',
            $pathTemplate,
            $expression,
        ));
    }
}
