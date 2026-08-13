<?php

declare(strict_types=1);

namespace Kinetis\Http\Routing;

use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Attributes\RouteAttribute;
use Kinetis\Http\Routing\Exception\DuplicateRouteException;
use Kinetis\Http\Routing\Exception\MethodNotAllowedException;
use Kinetis\Http\Routing\Exception\RouteNotFoundException;
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
     * Reflects every public method on the controller for a RouteAttribute
     * (Get, Post, ...) and registers a matching Route. Methods without one
     * are ignored, so a controller can freely mix routed actions with plain
     * helper methods.
     *
     * @param class-string $controllerClass
     */
    public function register(string $controllerClass): void
    {
        $reflection = new ReflectionClass($controllerClass);
        $classMiddleware = self::middlewareClassesFor($reflection);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $attributes = $method->getAttributes(RouteAttribute::class, ReflectionAttribute::IS_INSTANCEOF);

            if ($attributes === []) {
                continue;
            }

            $routeAttribute = $attributes[0]->newInstance();

            $route = new Route(
                httpMethod: $routeAttribute->httpMethod(),
                pathTemplate: $routeAttribute->path(),
                controllerClass: $controllerClass,
                controllerMethod: $method->getName(),
                status: $routeAttribute->status(),
                middleware: [...$classMiddleware, ...self::middlewareClassesFor($method)],
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
     * @return list<array{httpMethod:string,pathTemplate:string,controllerClass:string,controllerMethod:string,status:int,middleware:list<string>}>
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
     * @param list<array{httpMethod:string,pathTemplate:string,controllerClass:string,controllerMethod:string,status:int,middleware:list<string>}> $routes
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
