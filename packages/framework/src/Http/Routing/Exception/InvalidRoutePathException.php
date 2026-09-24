<?php

declare(strict_types=1);

namespace Kinetis\Http\Routing\Exception;

use RuntimeException;

/**
 * A declared path can't be turned into a working route: it isn't
 * absolute, it carries a control character, its `{...}` placeholder
 * syntax is malformed, or its `where` constraints are not self-contained
 * fragments that compile into a working matcher. Every case fails at
 * registration, where the mistake is, rather than as a silent permanent
 * 404 on the route's first real request.
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
     * A `{...}` expression that isn't a plain placeholder name. There is
     * no inline pattern syntax: a route constrains what a placeholder
     * admits through the verb attribute's `where` map instead.
     */
    public static function malformedPlaceholder(string $pathTemplate, string $expression): self
    {
        return new self(sprintf(
            'Route "%s" declares "%s", which is not a placeholder — a placeholder is "{name}", where name is a plain identifier. Constrain what a placeholder admits with the route attribute\'s where: map.',
            $pathTemplate,
            $expression,
        ));
    }

    public static function unknownConstraintPlaceholder(string $pathTemplate, string $name): self
    {
        return new self(sprintf(
            'Route "%s" declares a where: constraint for "{%s}", which is not a placeholder in that path.',
            $pathTemplate,
            $name,
        ));
    }

    /**
     * A `where` entry whose key isn't a string or whose value isn't a
     * non-empty string free of control characters. A literal control
     * byte is refused because the compiled pattern's delimiter is one.
     */
    public static function invalidConstraint(string $pathTemplate, int|string $key): self
    {
        return new self(sprintf(
            'Route "%s" declares an invalid where: entry at key %s — each entry maps a placeholder name to a non-empty PCRE fragment with no control characters.',
            $pathTemplate,
            is_string($key) ? "\"{$key}\"" : (string) $key,
        ));
    }

    /**
     * A fragment that does not compile on its own, or that closes a group
     * it did not open, leaves a group, class, comment or `\Q` quote open,
     * or names a group after a placeholder. Embedded as written, it could
     * detach the rest of the route from the match or overwrite another
     * capture even when the finished route compiles.
     */
    public static function uncontainedConstraint(string $pathTemplate, string $name, string $fragment): self
    {
        return new self(sprintf(
            'Route "%s" declares the where: constraint "%s" for "{%s}", which does not compile as a self-contained PCRE2 fragment. A fragment must compile on its own, close every group, character class, comment and \\Q...\\E quote it opens, close no group it did not open, and name no group after a placeholder.',
            $pathTemplate,
            $fragment,
            $name,
        ));
    }

    /**
     * An active `(*ACCEPT)` ends the whole match where it runs, so the
     * route's remaining literals and its `\z` anchor would never be tested.
     */
    public static function acceptingConstraint(string $pathTemplate, string $name, string $fragment): self
    {
        return new self(sprintf(
            'Route "%s" declares the where: constraint "%s" for "{%s}", which uses (*ACCEPT). (*ACCEPT) ends the match before the rest of the path is tested, so a constraint cannot use it.',
            $pathTemplate,
            $fragment,
            $name,
        ));
    }

    /**
     * Every fragment compiles alone, but the finished route does not —
     * for example, two fragments define the same group name.
     * $pcreError is `preg_last_error_msg()` from the construction probe.
     *
     * @param array<string,string> $where
     */
    public static function uncompilableConstraint(string $pathTemplate, array $where, string $pcreError): self
    {
        $constraints = [];

        foreach ($where as $name => $fragment) {
            $constraints[] = "{$name}: {$fragment}";
        }

        return new self(sprintf(
            'Route "%s" does not compile with its where: constraints (%s): %s. Each constraint is a PCRE2 fragment written without delimiters or anchors.',
            $pathTemplate,
            implode(', ', $constraints),
            $pcreError,
        ));
    }
}
