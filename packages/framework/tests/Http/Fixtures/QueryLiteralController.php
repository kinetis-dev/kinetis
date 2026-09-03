<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Query;

/**
 * KINETIS-76 follow-up: a #[Query] value only ever arrives as a raw
 * string — OpenAPI's own boolean query-serialization convention (the
 * literal spellings "true"/"false") needs Dispatcher's own source-
 * specific normalization before it reaches Hydrator's shared
 * type-mismatch check, unlike a JSON body's already-decoded boolean.
 */
final readonly class QueryLiteralController
{
    #[Get('/query-literals')]
    public function show(
        #[Query] bool $flag = false,
        #[Query] true $confirmed = true,
        #[Query] false $declined = false,
    ): array {
        return ['flag' => $flag, 'confirmed' => $confirmed, 'declined' => $declined];
    }
}
