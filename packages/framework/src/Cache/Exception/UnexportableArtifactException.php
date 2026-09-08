<?php

declare(strict_types=1);

namespace Kinetis\Cache\Exception;

use RuntimeException;

/**
 * A compiled value cannot be represented in the artifact at all: an
 * object other than an enum case reached the data `var_export()`
 * renders, and `\SomeClass::__set_state(...)` is a call most classes
 * cannot replay.
 *
 * A defect in what was compiled, never a failure to persist it — which
 * is why this is separate from {@see CacheWriteException}. The runtime's
 * compile-in-memory fallback continues past a persistence failure and
 * never past this: an artifact that cannot be written is a degraded
 * boot, while a section carrying a live object is wrong everywhere,
 * including in memory.
 *
 * A parameter default is refused earlier, by
 * {@see \Kinetis\Reflection\ParameterDefault} where the plan is
 * derived. What reaches here is a discovery section that has not reduced
 * its own data to the plain values an artifact carries.
 */
final class UnexportableArtifactException extends RuntimeException
{
    public static function object(string $keyPath, string $class): self
    {
        return new self(
            "Cannot compile the AOT cache: an instance of {$class} at \"{$keyPath}\" has no var_export() "
            . 'representation that can be required back. An artifact carries scalars, null, arrays of '
            . 'those, and enum cases — reduce the value to one of those before compiling it in, the way '
            . 'a JSON string carries a schema no PHP array could express.',
        );
    }
}
