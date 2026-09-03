<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Post;
use Kinetis\Tests\Validation\Fixtures\BuiltinCoverageRequest;

final readonly class BuiltinCoverageController
{
    #[Post('/builtin-coverage')]
    public function store(#[Body] BuiltinCoverageRequest $data): array
    {
        return [
            'tags' => $data->tags,
            'items' => $data->items,
            'note' => $data->note,
            'marker' => $data->marker,
            'confirmed' => $data->confirmed,
            'declined' => $data->declined,
        ];
    }
}
