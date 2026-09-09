<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

final readonly class UnitEnumFieldRequest
{
    public function __construct(
        public Weekday $day,
    ) {}
}
