<?php

declare(strict_types=1);

namespace Kinetis\Tests\Linting\Fixtures;

final class NoStaticProperty
{
    private array $instanceCache = [];

    public static function make(): self
    {
        return new self();
    }
}
