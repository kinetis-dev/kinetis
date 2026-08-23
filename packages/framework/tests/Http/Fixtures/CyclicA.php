<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

/** Half of a dependency cycle: autowiring this recurses into CyclicB. */
final readonly class CyclicA
{
    public function __construct(public CyclicB $b) {}
}
