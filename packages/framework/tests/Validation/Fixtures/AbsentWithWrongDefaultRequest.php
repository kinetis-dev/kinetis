<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\Absent;

final readonly class AbsentWithWrongDefaultRequest
{
    public function __construct(
        public string|null|Absent $title = null,
    ) {}
}
