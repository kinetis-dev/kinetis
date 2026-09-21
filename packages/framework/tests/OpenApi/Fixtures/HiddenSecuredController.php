<?php

declare(strict_types=1);

namespace Kinetis\Tests\OpenApi\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Hidden;
use Kinetis\Http\Attributes\Middleware;

#[Hidden]
final readonly class HiddenSecuredController
{
    #[Get('/hidden')]
    #[Middleware(AdminKeyMiddleware::class)]
    public function hidden(): array
    {
        return ['ok' => true];
    }
}
