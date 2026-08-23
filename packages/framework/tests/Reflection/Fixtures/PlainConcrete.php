<?php

declare(strict_types=1);

namespace Kinetis\Tests\Reflection\Fixtures;

use Kinetis\Http\Attributes\Get;

final class PlainConcrete
{
    #[Get('/plain')]
    public function plain(): array
    {
        return [];
    }
}
