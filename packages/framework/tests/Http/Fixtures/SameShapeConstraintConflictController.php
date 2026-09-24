<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;

/**
 * Two routes of one structural shape whose constraints never overlap —
 * still a duplicate, since constraints never select between handlers.
 */
final readonly class SameShapeConstraintConflictController
{
    #[Get('/items/{id}', where: ['id' => '\d+'])]
    public function byId(string $id): array
    {
        return ['id' => $id];
    }

    #[Get('/items/{slug}', where: ['slug' => '[a-z]+'])]
    public function bySlug(string $slug): array
    {
        return ['slug' => $slug];
    }
}
