<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;

/**
 * A route placeholder always captures one string, even when a `where`
 * constraint lets it span `/` — an array/iterable-typed path parameter
 * can never be satisfied by any request, unlike a #[Query] array
 * (?tags=a&tags=b works there). Kept as its own tiny, never-successfully-dispatchable
 * fixture specifically to prove Router::register() rejects this
 * declaration outright.
 */
final readonly class ImpossiblePathArrayController
{
    #[Get('/impossible-path-array/{tags}')]
    public function show(array $tags): array
    {
        return ['tags' => $tags];
    }
}
