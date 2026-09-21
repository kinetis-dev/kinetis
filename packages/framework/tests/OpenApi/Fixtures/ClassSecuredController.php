<?php

declare(strict_types=1);

namespace Kinetis\Tests\OpenApi\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Attributes\OpenApiSecurity;

/** The class declaration governs every route here that declares none. */
#[Middleware(AdminKeyMiddleware::class)]
#[OpenApiSecurity(TokenAuthMiddleware::class)]
final readonly class ClassSecuredController
{
    #[Get('/class/inherited')]
    public function inherited(): array
    {
        return ['ok' => true];
    }

    #[Get('/class/overridden')]
    #[OpenApiSecurity(AdminKeyMiddleware::class)]
    public function overridden(): array
    {
        return ['ok' => true];
    }
}
