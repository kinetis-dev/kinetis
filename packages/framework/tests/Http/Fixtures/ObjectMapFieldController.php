<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Post;
use Kinetis\Tests\Validation\Fixtures\ObjectMapFieldRequest;

final readonly class ObjectMapFieldController
{
    #[Post('/object-map-field')]
    public function store(#[Body] ObjectMapFieldRequest $data): array
    {
        return ['name' => $data->name, 'meta' => $data->meta];
    }
}
