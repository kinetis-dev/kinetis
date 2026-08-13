<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;

/**
 * Claims exactly the same requests as DuplicateRouteControllerA's
 * `/dup/{id}` — a different placeholder name doesn't change which paths
 * match, which is precisely what Route::conflictKey() normalizes away.
 */
final readonly class DuplicateRouteControllerB
{
    #[Get('/dup/{key}')]
    public function find(string $key): array
    {
        return ['key' => $key];
    }
}
