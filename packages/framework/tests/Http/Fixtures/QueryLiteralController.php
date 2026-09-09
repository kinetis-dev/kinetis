<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Query;

/**
 * A #[Query] value only ever arrives as a raw string, so it is resolved
 * under InputSource::Text — which is what admits OpenAPI's own boolean
 * query-serialization convention, the literal spellings "true"/"false",
 * unlike a JSON body's already-decoded boolean.
 */
final readonly class QueryLiteralController
{
    #[Get('/query-literals')]
    public function show(#[Query] bool $flag = false): array
    {
        return ['flag' => $flag];
    }
}
