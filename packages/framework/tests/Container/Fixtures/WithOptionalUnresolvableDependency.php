<?php

declare(strict_types=1);

namespace Kinetis\Tests\Container\Fixtures;

/**
 * Unresolvable is a real, instantiable class, so this dependency is
 * available rather than absent — and its own required string parameter
 * cannot be supplied. That failure belongs to the dependency and reaches
 * the caller; the default never stands in for it.
 */
final class WithOptionalUnresolvableDependency
{
    public function __construct(
        public readonly ?Unresolvable $addr = null,
    ) {}
}
