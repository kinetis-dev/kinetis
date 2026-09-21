<?php

declare(strict_types=1);

namespace Kinetis\Tests\OpenApi\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;

final readonly class ConflictingSecurityController
{
    #[Get('/conflicting')]
    #[Middleware(TokenAuthMiddleware::class)]
    #[Middleware(ConflictingTokenMiddleware::class)]
    public function conflicting(): array
    {
        return ['ok' => true];
    }
}
