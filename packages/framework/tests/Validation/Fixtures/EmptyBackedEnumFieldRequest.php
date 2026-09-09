<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

final readonly class EmptyBackedEnumFieldRequest
{
    public function __construct(
        public NoCases $choice,
    ) {}
}
