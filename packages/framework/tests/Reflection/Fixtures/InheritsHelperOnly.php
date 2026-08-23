<?php

declare(strict_types=1);

namespace Kinetis\Tests\Reflection\Fixtures;

use Kinetis\Http\Attributes\Get;

/**
 * Inherits only a plain, unrouted helper alongside declaring its own
 * route — the shape that has to stay legal.
 */
final class InheritsHelperOnly extends AbstractHelperBase
{
    #[Get('/own')]
    public function own(): array
    {
        return [];
    }
}
