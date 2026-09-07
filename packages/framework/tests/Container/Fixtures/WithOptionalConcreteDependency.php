<?php

declare(strict_types=1);

namespace Kinetis\Tests\Container\Fixtures;

/**
 * Optional against a concrete, instantiable class: the container builds
 * one, because the dependency is available rather than absent.
 */
final class WithOptionalConcreteDependency
{
    public function __construct(
        public readonly ?Counter $counter = null,
    ) {}
}
