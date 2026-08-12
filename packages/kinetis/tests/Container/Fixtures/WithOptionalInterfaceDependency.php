<?php

declare(strict_types=1);

namespace Kinetis\Tests\Container\Fixtures;

final class WithOptionalInterfaceDependency
{
    public function __construct(
        public readonly ?OptionalInterface $thing = null,
    ) {}
}
