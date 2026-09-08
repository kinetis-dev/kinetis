<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Exception;

use RuntimeException;

/**
 * Thrown at {@see \Kinetis\Broadcasting\BroadcastChannelRegistry::register()}
 * time — a malformed `#[BroadcastChannel]` method fails fast at
 * registration, not the first time a client happens to try authorizing
 * against it, the same discipline `EventListenerRegistry` already applies
 * to a malformed `#[Listener]` method. `fromArray()` reclassifies these
 * into `Kinetis\Cache\Exception\InvalidCacheArtifactException`, since a
 * cache artifact carrying one is stale data rather than a live
 * registration failure.
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

    /**
     * Every pair of patterns some channel name could match is rejected,
     * an identical pair included — see
     * {@see \Kinetis\Broadcasting\ChannelDefinition::overlaps()} for the
     * rule. There is no precedence between two authorizers, so the only
     * way a channel name has one answer is for exactly one pattern to
     * claim it.
     */
    public static function overlappingPattern(string $pattern, string $existingPattern, string $existingClass, string $existingMethod): self
    {
        return new self(sprintf(
            'The channel pattern "%s" overlaps "%s", already registered by %s::%s() — some channel name matches '
                . 'both, and no precedence decides between two authorizers. Give the patterns a different segment '
                . 'count or an unequal literal segment, or handle both cases inside one authorizer.',
            $pattern,
            $existingPattern,
            $existingClass,
            $existingMethod,
        ));
    }

    /**
     * A segment is either one literal or exactly one whole `{name}`
     * placeholder — `orders`, `{orderId}`. A brace anywhere else in a
     * segment (`order-{id}`, `{a}-{b}`, `orders.{id`) and an empty
     * segment are both rejected, so a placeholder always spans a whole
     * channel-name segment and a pattern's segment count is fixed by its
     * own literal dots.
     */
    public static function malformedSegment(string $pattern, string $segment): self
    {
        return new self(
            "The channel pattern \"{$pattern}\" has the invalid segment \"{$segment}\" — each dot-separated "
                . 'segment must be either a non-empty literal or exactly one whole {name} placeholder.',
        );
    }

    /**
     * A placeholder name must be unique across the whole pattern —
     * `orders.{id}.{id}` would otherwise compile to a PCRE regex with
     * two capture groups sharing one name. PHP's own
     * parameter-name-uniqueness rule makes that unreachable through a
     * live method signature, but a cache artifact never reflects a
     * method, so the check belongs where the pattern is parsed.
     */
    public static function duplicatePlaceholderName(string $pattern, string $name): self
    {
        return new self(
            "The channel pattern \"{$pattern}\" uses the placeholder name \"{$name}\" more than once — "
                . 'every placeholder in a pattern must have a distinct name.',
        );
    }
}
