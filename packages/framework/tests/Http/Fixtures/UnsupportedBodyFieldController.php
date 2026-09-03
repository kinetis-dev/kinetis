<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Post;
use Kinetis\Tests\Validation\Fixtures\ObjectFieldRequest;

/**
 * KINETIS-76 follow-up: a real registered HTTP route whose #[Body] DTO
 * carries an unsupported builtin-typed field. Used two ways: proving
 * OpenApiGenerator still refuses to describe it (schema-generation-time
 * rejection, unchanged), and, separately in DispatcherTest, proving the
 * route dispatches and rejects a real request with a clean 422 rather
 * than a raw TypeError — a guarantee that holds whether or not
 * /openapi.json (or generation of any kind) is ever hit for this route.
 */
final readonly class UnsupportedBodyFieldController
{
    #[Post('/unsupported-body-field')]
    public function store(#[Body] ObjectFieldRequest $data): array
    {
        return ['extra' => $data->extra];
    }
}
