<?php

declare(strict_types=1);

namespace Kinetis\Tests\Reflection\Fixtures;

/** A base carrying only ordinary shared behaviour, no route attributes. */
abstract class AbstractHelperBase
{
    public function plainHelper(): string
    {
        return 'not routed';
    }
}
