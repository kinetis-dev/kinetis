<?php

declare(strict_types=1);

namespace Kinetis\Http\Attributes;

use Attribute;

/**
 * Prepends a path segment to every route on the controller, so a set of
 * routes can be shared by a trait and mounted at a different path by each
 * controller that uses it:
 *
 *     trait CrudRoutes {
 *         #[Get('')]      public function index(): array { ... }
 *         #[Get('/{id}')] public function show(int $id): array { ... }
 *     }
 *
 *     #[RoutePrefix('/users')]  final class UserController  { use CrudRoutes; }
 *     #[RoutePrefix('/orders')] final class OrderController { use CrudRoutes; }
 *
 * Resolved at registration, so the compiled path is what duplicate
 * detection, the AOT cache, the OpenAPI document and `kinetis routes:list`
 * all see — none of them need to know a prefix was involved.
 *
 * Not inherited and not read from a trait, like every other attribute: the
 * prefix belongs to the concrete controller being registered, which is
 * what lets one trait serve several of them.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class RoutePrefix
{
    public function __construct(
        public string $prefix,
    ) {}

    /**
     * Joins this prefix with a route's own path. Stray slashes on either
     * half are left to Route, which normalizes every path it is given to
     * one canonical form — so this only has to guarantee a separator, and
     * a route may declare an empty path to sit at the prefix itself.
     */
    public function join(string $path): string
    {
        return rtrim($this->prefix, '/') . '/' . ltrim($path, '/');
    }
}
