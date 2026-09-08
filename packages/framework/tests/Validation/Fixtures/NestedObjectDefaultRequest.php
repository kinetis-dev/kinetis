<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use ArrayObject;

/**
 * The same defect one level down: a constant expression may build an
 * array around a `new` of its own, and an instance nested inside an
 * array default is shared exactly as widely as a bare one.
 */
final readonly class NestedObjectDefaultRequest
{
    public function __construct(
        public array $window = ['cursor' => new ArrayObject()],
    ) {}
}
