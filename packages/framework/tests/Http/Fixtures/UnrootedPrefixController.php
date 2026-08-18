<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\RoutePrefix;

#[RoutePrefix('users')]
final class UnrootedPrefixController
{
    #[Get('/{id}')]
    public function show(int $id): array
    {
        return ['id' => $id];
    }
}
