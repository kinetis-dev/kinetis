<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Post;
use Kinetis\Tests\Validation\Fixtures\CallableFieldRequest;

/**
 * `callable`'s own HTTP-level counterpart to UnsupportedBodyFieldController
 * — a route whose #[Body] DTO carries a callable-typed field, proving
 * the same guarantee holds for both rejected categories, not just
 * `object`: the route still registers and dispatches (422, never a raw
 * TypeError, and never an accepted arbitrary function name), whether or
 * not OpenApiGenerator::generate() is ever called for it.
 */
final readonly class UnsupportedCallableBodyFieldController
{
    #[Post('/unsupported-callable-body-field')]
    public function store(#[Body] CallableFieldRequest $data): array
    {
        return ['handler' => $data->handler];
    }
}
