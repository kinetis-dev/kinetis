<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Attributes\RoutePrefix;

/**
 * Every non-global prefix layer at once, to prove the full outer-to-inner
 * composition order in one route: VersionedMiddleware (class-level,
 * "/v1") is outermost, AdminScopedMiddleware (method-level, "/admin")
 * comes next — matching #[Middleware]'s own class-before-method execution
 * order exactly — then this controller's own #[RoutePrefix] ("/users"),
 * then the route's own declared path. Expected: "/v1/admin/users/{id}".
 */
#[RoutePrefix('/users')]
#[Middleware(VersionedMiddleware::class)]
final class MultiLayerPrefixController
{
    #[Get('/{id}')]
    #[Middleware(AdminScopedMiddleware::class)]
    public function show(int $id): array
    {
        return ['id' => $id];
    }
}
