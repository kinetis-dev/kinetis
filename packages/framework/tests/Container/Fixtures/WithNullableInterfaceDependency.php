<?php

declare(strict_types=1);

namespace Kinetis\Tests\Container\Fixtures;

/** Nullable with no default written out: absence still supplies null. */
final class WithNullableInterfaceDependency
{
    public function __construct(
        public readonly ?OptionalInterface $thing,
    ) {}
}
