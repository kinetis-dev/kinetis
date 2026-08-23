<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;

final readonly class DuplicateRouteControllerA
{
    #[Get('/dup/{id}')]
    public function show(int $id): array
    {
        return ['id' => $id];
    }
}
