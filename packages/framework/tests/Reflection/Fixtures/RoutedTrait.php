<?php

declare(strict_types=1);

namespace Kinetis\Tests\Reflection\Fixtures;

use Kinetis\Http\Attributes\Get;

trait RoutedTrait
{
    #[Get('/from-trait')]
    public function fromTrait(): array
    {
        return [];
    }
}
