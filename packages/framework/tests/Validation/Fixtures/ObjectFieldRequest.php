<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

final readonly class ObjectFieldRequest
{
    public function __construct(
        public object $extra,
    ) {}
}
