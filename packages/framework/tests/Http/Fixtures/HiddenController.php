<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Hidden;
use Kinetis\Http\Attributes\Post;

#[Hidden]
final readonly class HiddenController
{
    #[Post('/internal/status')]
    public function status(#[Body] HiddenRequest $data): array
    {
        return ['ok' => $data->ok];
    }
}
