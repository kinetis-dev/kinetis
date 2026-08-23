<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;

/** No prefix, so an empty path has nothing to mean. */
final class EmptyPathController
{
    #[Get('')]
    public function index(): array
    {
        return [];
    }
}
