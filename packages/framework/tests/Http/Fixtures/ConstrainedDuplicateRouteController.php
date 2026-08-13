<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;

/**
 * Same path as DuplicateRouteControllerA's `/dup/{id}` but with a
 * `{id:\d+}` constraint — a different match set (non-numeric segments
 * fall through to the unconstrained route), so registering both is
 * legal, ordered first-match-wins.
 */
final readonly class ConstrainedDuplicateRouteController
{
    #[Get('/dup/{id:\d+}')]
    public function showNumeric(int $id): array
    {
        return ['numeric' => $id];
    }
}
