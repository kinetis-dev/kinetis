<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use RuntimeException;

/** Registered, but blows up when the container tries to build it. */
final readonly class BrokenService
{
    public function __construct()
    {
        throw new RuntimeException('service construction failed');
    }
}
