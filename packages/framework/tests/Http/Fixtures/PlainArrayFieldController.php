<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Post;
use Kinetis\Tests\Validation\Fixtures\PlainArrayFieldRequest;

final readonly class PlainArrayFieldController
{
    #[Post('/plain-array-field')]
    public function store(#[Body] PlainArrayFieldRequest $data): array
    {
        return ['tags' => $data->tags, 'optionalTags' => $data->optionalTags];
    }
}
