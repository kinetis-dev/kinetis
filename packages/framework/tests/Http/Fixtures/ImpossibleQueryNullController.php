<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Query;

/**
 * KINETIS-76 follow-up: a required (no default) standalone-`null`-typed
 * #[Query] parameter can never be satisfied by any real request — a
 * query string is always a non-empty string when present, never PHP's
 * real null. Kept as its own tiny, never-successfully-dispatchable
 * fixture specifically to prove Dispatcher::derivePlan() rejects this
 * declaration outright, rather than shipping a route that would 422 on
 * every possible input forever.
 */
final readonly class ImpossibleQueryNullController
{
    #[Get('/impossible-query-null')]
    public function show(#[Query] null $marker): array
    {
        return ['marker' => $marker];
    }
}
