<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

/** The other half: autowiring this recurses back into CyclicA. */
final readonly class CyclicB
{
    public function __construct(public CyclicA $a) {}
}
