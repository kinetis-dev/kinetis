<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

final readonly class MutuallyRecursiveChild
{
    public function __construct(
        public MutuallyRecursiveParent $parent,
    ) {}
}
