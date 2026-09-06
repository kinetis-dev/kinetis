<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use ArrayAccess;
use Countable;

final readonly class IntersectionTypedFieldRequest
{
    public function __construct(
        public Countable&ArrayAccess $collection,
    ) {}
}
