<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\Absent;

final readonly class AbsentWithTwoValueTypesRequest
{
    public function __construct(
        public int|string|Absent $identifier = Absent::Value,
    ) {}
}
