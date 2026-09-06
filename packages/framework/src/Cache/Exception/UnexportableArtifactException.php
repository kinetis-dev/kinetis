<?php

declare(strict_types=1);

namespace Kinetis\Cache\Exception;

use RuntimeException;

/**
 * A compiled value cannot be represented in the artifact at all: an
 * object reached the data `var_export()` renders, and `\SomeClass::
 * __set_state(...)` is a call most classes cannot replay.
 *
 * A defect in what was compiled, never a failure to persist it — which
 * is why this is separate from {@see CacheWriteException}. The runtime's
 * compile-in-memory fallback continues past a persistence failure and
 * never past this: an artifact that cannot be written is a degraded
 * boot, while a plan carrying a live object is wrong everywhere,
 * including in memory.
 */
final class UnexportableArtifactException extends RuntimeException
{
    public static function object(string $keyPath, string $class): self
    {
        return new self(
            "Cannot compile the AOT cache: an instance of {$class} at \"{$keyPath}\" has no var_export() "
            . 'representation that can be required back. Most commonly this is a constructor default '
            . 'value that constructs an object — replace it with a plain scalar/array default, or make '
            . 'the parameter nullable and construct the object in the constructor body.',
        );
    }
}
