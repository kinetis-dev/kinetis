<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\Absent;
use UnitEnum;

/**
 * A presence union whose value type is a supertype of the marker itself.
 * `Absent::Value` satisfies `UnitEnum`, so nothing about the value type
 * would reject the marker — this is the declaration that proves the
 * marker is refused because it is the marker, not because it happened to
 * be the wrong shape.
 */
final readonly class AbsentEnumSupertypeRequest
{
    public function __construct(
        public UnitEnum|Absent $choice = Absent::Value,
    ) {}
}
