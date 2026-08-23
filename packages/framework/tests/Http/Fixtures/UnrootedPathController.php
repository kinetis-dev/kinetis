<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;

final class UnrootedPathController
{
    #[Get('users')]
    public function index(): array
    {
        return [];
    }
}
