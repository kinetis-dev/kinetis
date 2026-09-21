<?php

declare(strict_types=1);

namespace Kinetis\Tests\OpenApi\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Attributes\OpenApiSecurity;
use Kinetis\Tests\Http\Fixtures\MethodLevelMiddleware;

final readonly class SecuredController
{
    #[Get('/secured/token')]
    #[Middleware(TokenAuthMiddleware::class)]
    public function token(): array
    {
        return ['ok' => true];
    }

    #[Get('/secured/either')]
    #[Middleware(EitherAuthMiddleware::class)]
    public function either(): array
    {
        return ['ok' => true];
    }

    #[Get('/secured/optional')]
    #[Middleware(OptionalTokenMiddleware::class)]
    public function optional(): array
    {
        return ['ok' => true];
    }

    /** Two describers in sequence: both have to be satisfied. */
    #[Get('/secured/both')]
    #[Middleware(TokenAuthMiddleware::class)]
    #[Middleware(AdminKeyMiddleware::class)]
    public function both(): array
    {
        return ['ok' => true];
    }

    /** One scheme named twice, with different scopes each time. */
    #[Get('/secured/scopes')]
    #[Middleware(WriteScopeMiddleware::class)]
    #[Middleware(ReadScopeMiddleware::class)]
    public function scopes(): array
    {
        return ['ok' => true];
    }

    #[Get('/secured/plain')]
    #[Middleware(MethodLevelMiddleware::class)]
    public function plain(): array
    {
        return ['ok' => true];
    }

    /** The declaration replaces the inference; it does not add to it. */
    #[Get('/secured/explicit')]
    #[Middleware(TokenAuthMiddleware::class)]
    #[OpenApiSecurity(AdminKeyMiddleware::class)]
    public function explicit(): array
    {
        return ['ok' => true];
    }

    /**
     * Documented as needing no credential while TokenAuthMiddleware
     * still wraps it — the attribute publishes, it does not admit.
     */
    #[Get('/secured/anonymous')]
    #[Middleware(TokenAuthMiddleware::class)]
    #[OpenApiSecurity]
    public function anonymous(): array
    {
        return ['ok' => true];
    }
}
