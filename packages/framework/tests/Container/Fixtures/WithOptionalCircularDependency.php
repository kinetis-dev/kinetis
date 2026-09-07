<?php

declare(strict_types=1);

namespace Kinetis\Tests\Container\Fixtures;

/**
 * Optional against a concrete class whose own dependency graph closes on
 * itself: the cycle is the dependency's failure, and the default never
 * stands in for it.
 */
final class WithOptionalCircularDependency
{
    public function __construct(
        public readonly ?CircularA $a = null,
    ) {}
}
