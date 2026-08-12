<?php

declare(strict_types=1);

namespace Kinetis\Tests\Container\Fixtures;

/**
 * No default, not nullable — the negative case: a required class-typed
 * dependency that can't be autowired must still throw, not silently fall
 * back to anything.
 */
final class WithRequiredUnresolvableDependency
{
    public function __construct(
        public readonly Unresolvable $addr,
    ) {}
}
