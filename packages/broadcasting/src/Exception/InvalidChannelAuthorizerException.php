<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Exception;

use RuntimeException;

/**
 * Thrown at {@see \Kinetis\Broadcasting\BroadcastChannelRegistry::register()}
 * time — a malformed `#[BroadcastChannel]` method fails fast at
 * registration, not the first time a client happens to try authorizing
 * against it, the same discipline `EventListenerRegistry` already applies
 * to a malformed `#[Listener]` method.
 */
final class InvalidChannelAuthorizerException extends RuntimeException
{
    public static function wrongParameterCount(string $class, string $method, string $pattern, int $expected, int $actual): self
    {
        return new self(sprintf(
            '%s::%s() is #[BroadcastChannel(\'%s\')] and must take an optional leading CurrentUserInterface '
                . 'parameter plus exactly %d string parameter(s) named after the pattern\'s placeholders, in order; found %d.',
            $class,
            $method,
            $pattern,
            $expected,
            $actual,
        ));
    }

    public static function parameterNameMismatch(string $class, string $method, string $pattern, string $expected, string $actual): self
    {
        return new self(sprintf(
            '%s::%s() is #[BroadcastChannel(\'%s\')] and its parameter named "%s" must be named "%s", matching the pattern\'s own placeholder order.',
            $class,
            $method,
            $pattern,
            $actual,
            $expected,
        ));
    }

    public static function parameterNotString(string $class, string $method, string $parameter): self
    {
        return new self("{$class}::{$method}()'s parameter \${$parameter} must be typed string.");
    }

    public static function duplicatePattern(string $pattern, string $firstClass, string $firstMethod): self
    {
        return new self("The channel pattern \"{$pattern}\" is already registered by {$firstClass}::{$firstMethod}().");
    }

    /**
     * Covers two distinct cases `BroadcastChannelRegistry::patternRelation()`
     * can't give a principled order for: patterns that match exactly the
     * same channel names with identical specificity (differing only in
     * placeholder naming), and patterns that genuinely overlap for some
     * channel names without either one containing the other. Neither has
     * a defensible "which one wins" answer, so registration fails rather
     * than one silently winning by accident of discovery order.
     */
    public static function ambiguousPattern(string $pattern, string $existingPattern, string $existingClass, string $existingMethod): self
    {
        return new self(sprintf(
            'The channel pattern "%s" cannot be distinguished from the already-registered "%s" '
                . '(%s::%s()) — either they match the same channel names with identical specificity, or they '
                . 'overlap without either one being more specific. Give one a more specific literal segment, or '
                . 'remove the duplicate.',
            $pattern,
            $existingPattern,
            $existingClass,
            $existingMethod,
        ));
    }

    /**
     * `decomposeSegments()`'s own grammar restriction — see
     * `BroadcastChannelRegistry`'s class-level docblock for why it's
     * capped at one placeholder per segment: with more than one, a
     * segment's own language can no longer be characterized by a plain
     * prefix/suffix pair, and the precedence comparison this whole class
     * depends on stops being decidable by simple string comparison.
     */
    public static function tooManyPlaceholdersInSegment(string $pattern, string $segment): self
    {
        return new self(
            "The channel pattern \"{$pattern}\" has more than one placeholder in its \"{$segment}\" segment — "
                . 'each dot-separated segment may hold at most one {name} placeholder.',
        );
    }

    /**
     * A placeholder name must be unique across the *whole* pattern, not
     * just within one segment — `orders.{id}.{id}` would otherwise
     * compile to a PCRE regex with two capture groups sharing one name,
     * which PHP's own parameter-name-uniqueness rule already makes
     * unreachable through a live `#[BroadcastChannel]` method's
     * signature, but a cached artifact bypasses that check entirely by
     * design (`fromArray()` never reflects a method), so this has to be
     * enforced here, where both the live and cached path actually
     * compile a pattern.
     */
    public static function duplicatePlaceholderName(string $pattern, string $name): self
    {
        return new self(
            "The channel pattern \"{$pattern}\" uses the placeholder name \"{$name}\" more than once — "
                . 'every placeholder in a pattern must have a distinct name.',
        );
    }
}
