<?php

declare(strict_types=1);

namespace Kinetis\Http\Attributes;

use Attribute;
use ReflectionClass;

/**
 * Prepends a path segment to every route on the controller, so a set of
 * routes can be shared by a trait and mounted at a different path by each
 * controller that uses it:
 *
 *     trait CrudRoutes {
 *         #[Get('/')]     public function index(): array { ... }
 *         #[Get('/{id}')] public function show(int $id): array { ... }
 *     }
 *
 *     #[RoutePrefix('/users')]  final class UserController  { use CrudRoutes; }
 *     #[RoutePrefix('/orders')] final class OrderController { use CrudRoutes; }
 *
 * Also readable from a *middleware* class, not just a controller — a
 * controller referencing #[Middleware(VersionMiddleware::class)] picks up
 * whatever prefix VersionMiddleware itself declares, and every controller
 * referencing it moves together when that one declaration changes:
 *
 *     #[RoutePrefix('/v1')]
 *     final class VersionMiddleware implements MiddlewareInterface { ... }
 *
 *     #[Middleware(VersionMiddleware::class)]
 *     final class UserController { #[Get('/users')] public function index(): array { ... } }
 *
 * `/users` becomes `/v1/users` with no change to UserController itself.
 * See Router::register() for how a route's full prefix chain (global
 * middleware, then route-level middleware, then the controller's own
 * #[RoutePrefix], outer to inner) gets composed — this class only ever
 * reasons about one prefix at a time.
 *
 * Resolved at registration, so the compiled path is what duplicate
 * detection, the AOT cache, the OpenAPI document and `kinetis routes:list`
 * all see — none of them need to know a prefix was involved.
 *
 * Not inherited and not read from a trait, like every other attribute: the
 * prefix belongs to the concrete class being reflected, which is what lets
 * one trait serve several controllers. Must start with `/`, like any other
 * declared path — validated by Router::register(), which reads every
 * prefix source (controller and middleware alike) through declaredOn().
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
     * a route declaring `/` sits at the prefix itself.
     */
    public function join(string $path): string
    {
        return rtrim($this->prefix, '/') . '/' . ltrim($path, '/');
    }

    /**
     * Reads #[RoutePrefix] off every class in $classes that actually
     * carries one, in the given order — a class without the attribute, or
     * one that doesn't exist (a `@group` reference, most commonly: `@` is
     * never a valid class-string, so class_exists() already excludes it
     * with no separate check needed), is silently skipped rather than
     * treated as an error. Non-existence is a different problem this
     * method isn't responsible for catching; something else already fails
     * loudly the moment an unresolvable middleware class-string is
     * actually needed for dispatch.
     *
     * Keyed by class-string rather than returned as a plain list so a
     * caller validating rootedness (Router::register() — the "must start
     * with /" rule stays there, not here, so this class never has to
     * depend on Http\Routing's own exception type for what is otherwise a
     * pure attribute reader) can still report which class declared the
     * bad prefix.
     *
     * $classes accepts plain `list<string>` rather than `list<class-string>`
     * deliberately — Router::register() passes it a route's own middleware
     * list verbatim, which may include a `@name` group reference alongside
     * real class-strings (Attributes\Middleware::middlewareClass's own
     * type). class_exists() already returns false for one of those with
     * no special-casing needed, since `@` is never a valid class-string.
     *
     * @param list<string> $classes
     * @return array<class-string, self>
     */
    public static function declaredOn(array $classes): array
    {
        $prefixes = [];

        foreach ($classes as $class) {
            if (!class_exists($class)) {
                continue;
            }

            $attributes = (new ReflectionClass($class))->getAttributes(self::class);

            if ($attributes === []) {
                continue;
            }

            $prefixes[$class] = $attributes[0]->newInstance();
        }

        return $prefixes;
    }

    /**
     * Folds $prefixesOuterToInner around $path, the first prefix
     * contributing the leftmost path segment — the single place every
     * layer of prefix composition (global middleware, route-level
     * middleware, a controller's own #[RoutePrefix]) actually joins,
     * rather than three copies of the same rtrim/ltrim logic.
     */
    public static function joinAll(string $path, self ...$prefixesOuterToInner): string
    {
        $composed = $path;

        foreach (array_reverse($prefixesOuterToInner) as $prefix) {
            $composed = $prefix->join($composed);
        }

        return $composed;
    }
}
