<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

/**
 * Nothing binds this interface, and an interface is not something the
 * container can build on its own, so a parameter typed with it is
 * absent rather than broken.
 */
interface AbsentService
{
    public function label(): string;
}
