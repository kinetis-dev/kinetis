<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Query;
use Kinetis\Validation\Absent;

/**
 * A #[Query] parameter declaring the presence union a DTO field may
 * declare. It has no meaning here: a query key that never appeared is
 * already answered by the parameter's own default, and one request value
 * cannot be bound to two types. Kept as its own never-registrable
 * fixture to prove Dispatcher::derivePlan() rejects it.
 */
final readonly class PresenceUnionQueryController
{
    #[Get('/presence-union-query')]
    public function show(#[Query] string|Absent $term = Absent::Value): array
    {
        return ['term' => $term];
    }
}
