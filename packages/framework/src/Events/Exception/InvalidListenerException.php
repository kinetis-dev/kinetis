<?php

declare(strict_types=1);

namespace Kinetis\Events\Exception;

use Kinetis\Cache\Exception\CacheArtifactExceptionInterface;
use RuntimeException;

/**
 * A #[Listener] method doesn't have exactly one class-typed parameter —
 * the event class can't be inferred from anything else, since the
 * attribute itself carries no arguments. Also thrown by
 * EventListenerRegistry::fromArray() for a compiled cache artifact that
 * doesn't represent a valid set of listener registrations — a malformed
 * entry, a malformed event key/list, or a duplicate {class, method} pair
 * — each discovered a different way but all the same underlying claim:
 * this is not a valid listener registration.
 *
 * Implements CacheArtifactExceptionInterface for the fromArray() case
 * specifically — this is what lets BootSequence's cache-bundle loaders
 * classify it as "the generation is corrupt, compile fresh instead"
 * rather than an uncaught hard failure at boot, without also catching
 * an unrelated Throwable a fresh, live discovery pass might raise. The
 * register()-time throw (the malformed-method case above) implements
 * the same interface incidentally, since it's the same class — nothing
 * currently relies on that half being classified this way, since
 * register() is never called while reconstructing from cached data.
 */
final class InvalidListenerException extends RuntimeException implements CacheArtifactExceptionInterface
{
    public static function forMethod(string $class, string $method): self
    {
        return new self("\"{$class}::{$method}()\" is not a valid #[Listener]: it must declare exactly one class-typed parameter, the event it listens for.");
    }

    public static function forInvalidEventKey(mixed $eventClass): self
    {
        $described = is_string($eventClass) ? "\"{$eventClass}\"" : 'a non-string key';

        return new self("A compiled listener registry has {$described} as an event key, which is not a valid class-string — the cache is stale or corrupt.");
    }

    public static function forNonListEntries(string $eventClass): self
    {
        return new self("The compiled listener entries for \"{$eventClass}\" are not a plain, densely-indexed list — the cache is stale or corrupt.");
    }

    public static function forMalformedCacheEntry(string $eventClass): self
    {
        return new self(
            "A compiled listener entry for \"{$eventClass}\" does not have exactly the required fields "
            . '(class, method, priority, queued), each correctly typed, with class/method shaped like a '
            . 'valid class-string/identifier — the cache is stale or corrupt.',
        );
    }

    public static function forDuplicateCacheEntry(string $eventClass, string $class, string $method): self
    {
        return new self(
            "The compiled listener entries for \"{$eventClass}\" name \"{$class}::{$method}()\" more than once — "
            . 'the cache is stale or corrupt.',
        );
    }
}
