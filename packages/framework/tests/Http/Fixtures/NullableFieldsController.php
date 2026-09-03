<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Post;
use Kinetis\Tests\Validation\Fixtures\NullableFieldsRequest;

final readonly class NullableFieldsController
{
    #[Post('/nullable-fields')]
    public function store(#[Body] NullableFieldsRequest $data): array
    {
        return [
            'requiredNullable' => $data->requiredNullable,
            'optionalNullable' => $data->optionalNullable,
            'optionalItem' => $data->optionalItem?->quantity,
            'optionalItems' => $data->optionalItems === null ? null : count($data->optionalItems),
        ];
    }
}
