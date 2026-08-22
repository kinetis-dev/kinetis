<?php

declare(strict_types=1);

namespace Kinetis\Http\Routing;

use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Attributes\RouteAttribute;
use Kinetis\Http\Attributes\RoutePrefix;
use Kinetis\Http\Routing\Exception\DuplicateRouteException;
use Kinetis\Http\Routing\Exception\InvalidRoutePathException;
use Kinetis\Http\Routing\Exception\MethodNotAllowedException;
use Kinetis\Http\Routing\Exception\RouteNotFoundException;
use Kinetis\Reflection\AttributeScope;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;

final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /**
     * Maintained by register() only — fromArray() loads a compiled cache
     * whose routes already went through register()'s own conflict check
     * when the cache was built, so re-checking there would just re-pay
     * the cost the cache exists to remove.
     *
     * @var array<string, Route>
     */
    private array $routesByConflictKey = [];

    /**
     * Reflects every public method the controller itself declares for a
     * RouteAttribute (Get, Post, ...) and registers a matching Route.
     * Methods without one are ignored, so a controller can freely mix
     * routed actions with plain helper methods.
     *
     * A routed method inherited from a parent is rejected rather than
     * registered: its #[Get] belongs to the parent while the child's own
     * attributes would go unread. See Kinetis\Reflection\AttributeScope.
     *
     * A path or #[RoutePrefix] that isn't rooted is rejected too — see
     * Exception\InvalidRoutePathException.
     *
     * $globalMiddleware is the project's own already-sorted, outermost-first
     * #[AsGlobalMiddleware] list (GlobalMiddlewareDiscovery::discoverAll()
     * or the compiled HttpCache's own copy of it) — every route on this
     * controller sits behind whatever prefix those classes declare, ahead
     * of anything declared here. A route's final path composes, outer to
     * inner: the global-middleware chain, then this controller's own
     * route-level #[Middleware(...)] chain (class-level before
     * method-level, matching their execution order exactly), then this
     * controller's own #[RoutePrefix], then the route's own declared path.
     * A `@group` middleware reference never contributes here — group
     * membership isn't resolved until Kernel construction, well after
     * routing, so RoutePrefix::declaredOn() simply never finds a real class
     * behind one (see its own doc comment).
     *
     * @param class-string $controllerClass
     * @param list<class-string> $globalMiddleware
     */
    public function register(string $controllerClass, array $globalMiddleware = []): void
    {
        $reflection = AttributeScope::reflect($controllerClass);
        $classMiddleware = self::middlewareClassesFor($reflection);

        $globalPrefixes = self::rootedPrefixes($globalMiddleware);
        $controllerPrefix = self::rootedPrefixes([$controllerClass]);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $attributes = $method->getAttributes(RouteAttribute::class, ReflectionAttribute::IS_INSTANCEOF);

            if ($attributes === []) {
                continue;
            }

            // After the attribute check, so a controller may inherit plain
            // helper methods — only an inherited *routed* method is an error.
            AttributeScope::assertDeclares($method, $controllerClass);

            $routeAttribute = $attributes[0]->newInstance();
            $declaredPath = $routeAttribute->path();

            // Route paths are absolute, so anything not rooted is a typo
            // rather than a shorthand — the empty string included, which
            // normalizes to "/" and would quietly claim the root route.
            if (!str_starts_with($declaredPath, '/')) {
                throw InvalidRoutePathException::forRoute($controllerClass, $method->getName(), $declaredPath);
            }

            $methodMiddleware = self::middlewareClassesFor($method);
            $routeMiddlewarePrefixes = self::rootedPrefixes([...$classMiddleware, ...$methodMiddleware]);

            $pathTemplate = RoutePrefix::joinAll(
                $declaredPath,
                ...$globalPrefixes,
                ...$routeMiddlewarePrefixes,
                ...$controllerPrefix,
            );

            $route = new Route(
                httpMethod: $routeAttribute->httpMethod(),
                pathTemplate: $pathTemplate,
                controllerClass: $controllerClass,
                controllerMethod: $method->getName(),
                status: $routeAttribute->status(),
                middleware: [...$classMiddleware, ...$methodMiddleware],
            );

            // Matching is first-match-wins, so a second route claiming
            // exactly the same requests (see Route::conflictKey()) would
            // silently never run — rejected here instead, the same
            // fail-fast-at-registration discipline CommandRegistry applies
            // to a duplicate command name. Routes that merely overlap
            // (`/users/{id}` vs. `/users/self`) stay legal; ordering is
            // the feature there.
            $key = $route->conflictKey();
            $existing = $this->routesByConflictKey[$key] ?? null;

            if ($existing !== null) {
                throw DuplicateRouteException::forConflict($existing, $route);
            }

            $this->routesByConflictKey[$key] = $route;
            $this->routes[] = $route;
        }
    }

    /**
     * RoutePrefix::declaredOn() plus the one validation rule that stays
     * here rather than in Attributes\RoutePrefix itself: "must start with
     * /", reported against whichever class actually declared the bad
     * value. Keeping the throw here, not in declaredOn(), is what lets
     * that method stay a pure attribute reader with no dependency on
     * Http\Routing's own exception type.
     *
     * @param list<string> $classes
     * @return list<RoutePrefix>
     */
    private static function rootedPrefixes(array $classes): array
    {
        $prefixes = RoutePrefix::declaredOn($classes);

        foreach ($prefixes as $class => $prefix) {
            if (!str_starts_with($prefix->prefix, '/')) {
                throw InvalidRoutePathException::forPrefix($class, $prefix->prefix);
            }
        }

        return array_values($prefixes);
    }

    /**
     * Class-level #[Middleware] runs outermost (applies to every route on
     * the controller); method-level appends, closer to the controller —
     * both discovered here rather than adding a second reflection pass,
     * since register() already walks the class and each routed method
     * once each.
     *
     * @param ReflectionClass<object>|ReflectionMethod $reflector
     * @return list<string> class-strings and/or `@name` group references
     */
    private static function middlewareClassesFor(ReflectionClass|ReflectionMethod $reflector): array
    {
        return array_map(
            static fn (ReflectionAttribute $attribute) => $attribute->newInstance()->middlewareClass,
            $reflector->getAttributes(Middleware::class),
        );
    }

    /**
     * @return list<Route>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * Dumps every registered route's constructor scalars — not the compiled
     * regex/paramNames, since Route's own constructor cheaply recomputes
     * those regardless of provenance. Used by Kinetis\Cache\Compiler to
     * produce a var_export()-able artifact with no live objects in it.
     *
     * @return list<array{httpMethod:string,pathTemplate:string,controllerClass:class-string,controllerMethod:string,status:int,middleware:list<string>}>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (Route $route): array => [
                'httpMethod' => $route->httpMethod,
                'pathTemplate' => $route->pathTemplate,
                'controllerClass' => $route->controllerClass,
                'controllerMethod' => $route->controllerMethod,
                'status' => $route->status,
                'middleware' => $route->middleware,
            ],
            $this->routes,
        );
    }

    /**
     * Reconstructs a Router from toArray()'s output with zero reflection —
     * the compiled-cache load path's counterpart to register().
     *
     * @param list<array{httpMethod:string,pathTemplate:string,controllerClass:class-string,controllerMethod:string,status:int,middleware:list<string>}> $routes
     */
    public static function fromArray(array $routes): self
    {
        $router = new self();

        foreach ($routes as $route) {
            $router->routes[] = new Route(
                httpMethod: $route['httpMethod'],
                pathTemplate: $route['pathTemplate'],
                controllerClass: $route['controllerClass'],
                controllerMethod: $route['controllerMethod'],
                status: $route['status'],
                middleware: $route['middleware'],
            );
        }

        return $router;
    }

    /**
     * @throws RouteNotFoundException no route's path template matches $path
     * @throws MethodNotAllowedException a route matches $path but not $method
     */
    public function match(string $method, string $path): RouteMatch
    {
        $allowedMethods = [];

        foreach ($this->routes as $route) {
            $params = $route->matchPath($path);

            if ($params === null) {
                continue;
            }

            $allowedMethods[] = $route->httpMethod;

            if ($route->httpMethod === $method) {
                return new RouteMatch($route, $params);
            }
        }

        if ($allowedMethods !== []) {
            throw MethodNotAllowedException::forPath($path, $allowedMethods);
        }

        throw RouteNotFoundException::forPath($path);
    }
}
