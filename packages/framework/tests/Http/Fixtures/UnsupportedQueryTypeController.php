<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Query;

/**
 * A #[Query] parameter typed outside
 * Hydrator::SUPPORTED_BUILTIN_TYPES — a query string carries text only,
 * so no request could ever supply PHP's real null. Kept as its own
 * tiny, never-registrable fixture to prove Dispatcher::derivePlan()
 * rejects the declaration.
 */
final readonly class UnsupportedQueryTypeController
{
    #[Get('/unsupported-query-type')]
    public function show(#[Query] null $marker): array
    {
        return ['marker' => $marker];
    }
}
