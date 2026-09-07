<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Query;

/**
 * A #[Query] value only ever arrives as a raw string, so OpenAPI's own
 * boolean query-serialization convention — the literal spellings
 * "true"/"false" — needs Hydrator::normalizeTextualBoolean() before it
 * reaches the shared type-mismatch check, unlike a JSON body's
 * already-decoded boolean.
 */
final readonly class QueryLiteralController
{
    #[Get('/query-literals')]
    public function show(#[Query] bool $flag = false): array
    {
        return ['flag' => $flag];
    }
}
