<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

/**
 * Nothing binds this interface and no fixture implements it: a
 * parameter typed against it is absent.
 */
interface AbsentService
{
    public function label(): string;
}
