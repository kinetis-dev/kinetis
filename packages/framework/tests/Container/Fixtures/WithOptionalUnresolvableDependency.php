<?php

declare(strict_types=1);

namespace Kinetis\Tests\Container\Fixtures;

/**
 * Unresolvable is a declared class, so it is resolved rather than
 * treated as absent — and its own required string parameter cannot be
 * supplied. That failure belongs to the dependency and reaches the
 * caller.
 */
final class WithOptionalUnresolvableDependency
{
    public function __construct(
        public readonly ?Unresolvable $addr = null,
    ) {}
}
