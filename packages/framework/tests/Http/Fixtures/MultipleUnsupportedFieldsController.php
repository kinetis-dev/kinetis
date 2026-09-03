<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Post;
use Kinetis\Tests\Validation\Fixtures\MultipleUnsupportedFieldsRequest;

final readonly class MultipleUnsupportedFieldsController
{
    #[Post('/multiple-unsupported-fields')]
    public function store(#[Body] MultipleUnsupportedFieldsRequest $data): array
    {
        return ['extra' => $data->extra, 'handler' => $data->handler];
    }
}
