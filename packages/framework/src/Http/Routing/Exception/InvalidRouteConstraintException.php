<?php

declare(strict_types=1);

namespace Kinetis\Http\Routing\Exception;

use RuntimeException;

/**
 * A route's own path template can't compile into a working matcher — a
 * duplicate `{name}` placeholder, or a `{name:pattern}` constraint whose
 * pattern isn't valid PCRE. Left uncompiled, either failure mode surfaces
 * as a silent, permanent 404 on every request to that route (preg_match()
 * returns false rather than throwing on a compile error), discoverable
 * only from a stray PHP warning — so this fails at registration instead,
 * naming the actual problem.
 */
final class InvalidRouteConstraintException extends RuntimeException
{
    public static function duplicatePlaceholderName(string $pathTemplate, string $name): self
    {
        return new self(
            "Route \"{$pathTemplate}\" declares the placeholder \"{{$name}}\" more than once — every "
            . 'placeholder in one path template needs a distinct name, since two identically-named '
            . 'capture groups can\'t compile into one working regex.',
        );
    }

    public static function malformedPattern(string $pathTemplate, string $name, string $pattern): self
    {
        return new self(
            "Route \"{$pathTemplate}\"'s \"{{$name}:{$pattern}}\" constraint is not a valid regex fragment — "
            . 'check for unbalanced parentheses/brackets or another PCRE syntax error in the pattern.',
        );
    }

    /**
     * Extended mode makes an unescaped `#` open a comment running to the
     * end of the line, so a `}` after one inside a constraint would stop
     * being the placeholder's own closing brace — and whether the mode is
     * on at a given byte is flag *scope*, not a construct with a fixed
     * opener and closer that Route's scanner could skip over. Rejected
     * here rather than mis-scanned into a route that silently matches the
     * wrong thing; see Route::unsupportedConstruct().
     */
    public static function extendedModeNotSupported(string $pathTemplate, string $name, string $pattern): self
    {
        return new self(
            "Route \"{$pathTemplate}\"'s \"{{$name}:{$pattern}}\" constraint turns on PCRE's extended mode "
            . '("x"), which route constraints do not support: it would make an unescaped "#" start a comment, '
            . 'and a "}" inside that comment would no longer close the placeholder. Write the pattern without '
            . 'the "x" flag — a route constraint is a single fragment with no need for the whitespace and '
            . 'comments extended mode exists to allow.',
        );
    }

    /**
     * A `(*...)` verb's own shape depends on which verb it is: some
     * carry free text that may contain a brace and end at the first `)`
     * (`(*MARK:})`), others hold a whole sub-pattern with nested
     * parentheses (`(*atomic:a(b))`). Telling them apart means tracking
     * PCRE's verb list, so Route's scanner refuses them by name instead
     * of mis-reading one as malformed; see Route::unsupportedConstruct().
     */
    public static function controlVerbNotSupported(string $pathTemplate, string $name, string $pattern): self
    {
        return new self(
            "Route \"{$pathTemplate}\"'s \"{{$name}:{$pattern}}\" constraint uses a PCRE control verb (\"(*...)\"), "
            . 'which route constraints do not support: some verbs end at their first ")" while others hold a '
            . 'nested sub-pattern, so a "}" inside one cannot be told apart from the brace closing the '
            . 'placeholder. Rewrite without it — "(?>...)" covers atomic grouping, and the backtracking verbs '
            . 'have no meaning in a single-fragment route constraint.',
        );
    }

    /**
     * No single constraint's own pattern is individually invalid, but
     * the route's combined matcher still fails to compile — most likely
     * PCRE's own limits (too many capture groups, pattern too long),
     * hit only once every constraint is assembled together.
     */
    public static function malformedRoute(string $pathTemplate): self
    {
        return new self(
            "Route \"{$pathTemplate}\" does not compile into a working matcher, even though none of its "
            . 'individual constraints are invalid on their own — likely a PCRE limit (too many capture '
            . 'groups, or the combined pattern is too long).',
        );
    }
}
