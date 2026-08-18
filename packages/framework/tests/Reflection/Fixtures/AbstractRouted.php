<?php

declare(strict_types=1);

namespace Kinetis\Tests\Reflection\Fixtures;

use Kinetis\Http\Attributes\Get;

abstract class AbstractRouted
{
    #[Get('/from-parent')]
    public function fromParent(): array
    {
        return [];
    }

    public function plainHelper(): string
    {
        return 'not routed';
    }
}
