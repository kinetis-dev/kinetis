<?php

declare(strict_types=1);

namespace Kinetis\Tests\Container\Fixtures;

final class WithOptionalAbstractDependency
{
    public function __construct(
        public readonly ?OptionalAbstract $thing = null,
    ) {}
}
