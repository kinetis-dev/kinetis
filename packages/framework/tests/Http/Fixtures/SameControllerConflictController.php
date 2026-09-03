<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;

/**
 * Two separate methods on one controller whose attributes resolve to the
 * exact same {httpMethod, path} conflict key — proving register()'s
 * conflict check also applies within a single controller's own pending
 * batch, not just against already-committed routes from a different one.
 */
final readonly class SameControllerConflictController
{
    #[Get('/self-conflict')]
    public function first(): array
    {
        return [];
    }

    #[Get('/self-conflict')]
    public function second(): array
    {
        return [];
    }
}
