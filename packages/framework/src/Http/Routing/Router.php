<?php

declare(strict_types=1);

namespace Kinetis\Http\Routing;

use Kinetis\Cache\Exception\ArtifactValidation;
use Kinetis\Cache\Exception\CacheArtifactExceptionInterface;
use Kinetis\Cache\Exception\InvalidCacheArtifactException;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Attributes\RouteAttribute;
use Kinetis\Http\Attributes\RoutePrefix;
use Kinetis\Http\Dispatcher;
use Kinetis\Http\Routing\Exception\ConflictingRegistrationContextException;
use Kinetis\Http\Routing\Exception\DuplicateRouteException;
use Kinetis\Http\Routing\Exception\InvalidRoutePathException;
use Kinetis\Http\Routing\Exception\MethodNotAllowedException;
use Kinetis\Http\Routing\Exception\RouteNotFoundException;
use Kinetis\Reflection\AttributeScope;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

final class Router
{
    private const array ROUTE_ENTRY_KEYS = [
        'httpMethod', 'pathTemplate', 'controllerClass', 'controllerMethod', 'status', 'middleware',
    ];

    /** @var list<Route> */
    private array $routes = [];

    /**
     * Maintained by both `register()` and `fromArray()` — a compiled
     * artifact is expected to have already passed `register()`'s own
     * conflict check when it was built, but `fromArray()` re-checks
     * anyway rather than trusting that a hand-edited or otherwise
     * corrupt artifact still holds: the whole point of validating a
     * cache artifact's shape is not extending that trust to its
     * *content*'s own invariants either.
     *
     * @var array<string, Route>
     */
    private array $routesByConflictKey = [];

    /**
     * A class already registered is a safe no-op on a second call *only
     * when the second call's `$globalMiddleware` context is identical to
     * the one its already-committed routes were actually built under* —
     * the invariant every discovery source relies on instead of keeping
     * its own deduplication bookkeeping (see `RouteDiscovery`, which used
     * to carry an external `$seen` set purely to avoid this, the same
     * problem `EventListenerRegistry` already solved for `#[Listener]`).
     * `Router` is public API, so a caller passing a genuinely different
     * context on a repeat call — a real mistake, not a duplicate scan —
     * must not be told its second registration silently "worked" while
     * the routes actually in the router still reflect the first,
     * different context; see `register()`'s own doc comment for how the
     * signature stored here is computed and compared.
     *
     * `fromArray()` reconstructs this map too, but every entry it adds
     * is `null` rather than a real signature: a compiled route's own
     * data carries no record of the `$globalMiddleware` list its
     * controller was originally registered under (that composition is
     * already baked into the stored `pathTemplate` by the time it's
     * serialized), so there is no signature to recover, and a `null`
     * entry can never equal a live call's own signature — always a real
     * string, even for an empty list — deliberately, so a live
     * `register()` call for a class that came from a compiled artifact
     * is always rejected rather than silently trusted against a context
     * that genuinely cannot be verified.
     *
     * @var array<class-string, string|null>
     */
    private array $registrationContexts = [];

    /**
     * Reflects every public method the controller itself declares for a
     * RouteAttribute (Get, Post, ...) and registers a matching Route —
     * every RouteAttribute a method carries, not just the first: a
     * method may legally declare more than one (`#[Get]` plus
     * `#[Head]`, say), each producing its own route sharing the same
     * middleware/prefix/controller method. Two attributes producing the
     * exact same {httpMethod, path} — including two of the identical
     * type declared twice — collide via the same conflict check below.
     * Methods without any RouteAttribute are ignored, so a controller
     * can freely mix routed actions with plain helper methods.
     *
     * Registration is atomic per controller: every candidate route is
     * reflected, instantiated, and conflict-checked into local state
     * first (against both already-committed routes and every other
     * candidate this same call has staged so far, since two methods on
     * one controller can conflict with each other too) — nothing is
     * committed to `$routes`/`$routesByConflictKey` until the whole
     * controller succeeds. A later method's bad path/prefix/constraint,
     * or any conflict, therefore leaves *zero* of this controller's
     * routes installed, not just the ones reflected before the failure
     * — the same all-or-nothing discipline `EventListenerRegistry::
     * register()` already applies to `#[Listener]`. Retrying the same
     * still-invalid class throws again, every time, rather than
     * silently becoming a no-op — it's never marked registered on a
     * failed attempt.
     *
     * A repeat call for an already-registered `$controllerClass` is only
     * a safe no-op when its `$globalMiddleware` context is identical to
     * the one already recorded for that class — compared as a canonical,
     * order-preserving signature (`implode("\0", $globalMiddleware)`; a
     * middleware class-string or `@name` group reference can never
     * itself contain a NUL byte, so this can't collide two genuinely
     * different lists). A different context throws
     * `Exception\ConflictingRegistrationContextException` instead of
     * silently no-opping — `Router` is public API, and a caller passing
     * a different global-middleware list on a second call is a real
     * mistake, not the harmless overlap this idempotency exists for; the
     * routes already in the router still reflect the *first* context
     * regardless, so pretending the second call "worked" would be a lie
     * about which context actually produced them. A class reconstructed
     * via `fromArray()` carries no recorded context at all (see
     * `$registrationContexts`'s own doc comment) and so always throws
     * this same exception on any later live `register()` call for it,
     * rather than risk trusting an unknowable match.
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
        $contextSignature = self::registrationContextSignature($globalMiddleware);

        if (array_key_exists($controllerClass, $this->registrationContexts)) {
            if ($this->registrationContexts[$controllerClass] === $contextSignature) {
                return;
            }

            throw $this->registrationContexts[$controllerClass] === null
                ? ConflictingRegistrationContextException::forCachedClass($controllerClass)
                : ConflictingRegistrationContextException::forClass($controllerClass);
        }

        $reflection = AttributeScope::reflect($controllerClass);
        $classMiddleware = self::middlewareClassesFor($reflection);

        $globalPrefixes = self::rootedPrefixes($globalMiddleware);
        $controllerPrefix = self::rootedPrefixes([$controllerClass]);

        /** @var list<Route> $pending */
        $pending = [];
        /** @var array<string, Route> $pendingByConflictKey */
        $pendingByConflictKey = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $attributes = $method->getAttributes(RouteAttribute::class, ReflectionAttribute::IS_INSTANCEOF);

            if ($attributes === []) {
                continue;
            }

            // After the attribute check, so a controller may inherit plain
            // helper methods — only an inherited *routed* method is an error.
            AttributeScope::assertDeclares($method, $controllerClass);

            $methodMiddleware = self::middlewareClassesFor($method);
            $routeMiddlewarePrefixes = self::rootedPrefixes([...$classMiddleware, ...$methodMiddleware]);

            foreach ($attributes as $attribute) {
                $routeAttribute = $attribute->newInstance();
                $declaredPath = $routeAttribute->path();

                // Route paths are absolute, so anything not rooted is a typo
                // rather than a shorthand — the empty string included, which
                // normalizes to "/" and would quietly claim the root route.
                if (!str_starts_with($declaredPath, '/')) {
                    throw InvalidRoutePathException::forRoute($controllerClass, $method->getName(), $declaredPath);
                }

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

                // Dispatcher::derivePlan() is otherwise only ever called
                // lazily, on this route's first real dispatch — so a
                // route whose own parameter bindings can never succeed (a
                // required standalone-null-typed #[Query]/path parameter,
                // see UnresolvableParameterException::forImpossibleQueryOrPathNull())
                // needs its own guarantee here too, at the one boundary
                // every route passes through regardless of deployment
                // shape, rather than only failing the moment a real
                // client actually dispatches to it. Called here — its own
                // result discarded, this is validation only — live
                // discovery reaches it directly; Kinetis\Cache\Compiler's
                // own AOT build reaches it too, since that's how it
                // discovers routes to compile in the first place, so a
                // route failing this check can never make it into a
                // compiled artifact either — closing the loop for
                // Router::fromArray()'s own load path without fromArray()
                // needing a live ReflectionMethod to re-check with.
                Dispatcher::derivePlan($method, $route);

                // Matching is first-match-wins, so a second route claiming
                // exactly the same requests (see Route::conflictKey()) would
                // silently never run — rejected here instead, the same
                // fail-fast-at-registration discipline CommandRegistry applies
                // to a duplicate command name. Routes that merely overlap
                // (`/users/{id}` vs. `/users/self`) stay legal; Route::
                // compareForMatching() is what decides between them, not
                // registration order.
                $key = $route->conflictKey();
                $existing = $this->routesByConflictKey[$key] ?? $pendingByConflictKey[$key] ?? null;

                if ($existing !== null) {
                    throw DuplicateRouteException::forConflict($existing, $route);
                }

                $pendingByConflictKey[$key] = $route;
                $pending[] = $route;
            }
        }

        foreach ($pending as $route) {
            $this->routesByConflictKey[$route->conflictKey()] = $route;
            $this->routes[] = $route;
        }

        $this->registrationContexts[$controllerClass] = $contextSignature;

        usort($this->routes, Route::compareForMatching(...));
    }

    /**
     * A canonical, order-preserving signature for a `$globalMiddleware`
     * list — see `register()`'s own doc comment for why this, rather
     * than `true`, is what gets recorded per registered class.
     *
     * @param list<class-string> $globalMiddleware
     */
    private static function registrationContextSignature(array $globalMiddleware): string
    {
        return implode("\0", $globalMiddleware);
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
     * the compiled-cache load path's counterpart to register(). Validates
     * every entry's own field shape independently of whatever the
     * caller already checked (HttpCache::fromArray() already validates
     * the identical shape when this is reached via a compiled artifact,
     * but this method has no way to know it was), and reclassifies any
     * other failure Route's own constructor raises while reconstructing
     * one (an unrooted path, most commonly) as the same artifact-failure
     * type — Route's constructor throwing InvalidRoutePathException for
     * a genuinely bad path declared in source code is a real application
     * bug; the identical throw while replaying data read back from a
     * cache file means the file itself is what's bad.
     *
     * @param list<array{httpMethod:string,pathTemplate:string,controllerClass:class-string,controllerMethod:string,status:int,middleware:list<string>}> $routes
     * @throws CacheArtifactExceptionInterface
     */
    public static function fromArray(array $routes): self
    {
        if (!array_is_list($routes)) {
            throw InvalidCacheArtifactException::wrongFieldType('Router', 'routes', 'a list');
        }

        $router = new self();

        foreach ($routes as $route) {
            if (!is_array($route)) {
                throw InvalidCacheArtifactException::malformedEntry('Router route', 'a non-array entry');
            }

            ArtifactValidation::exactKeys($route, 'Router route', self::ROUTE_ENTRY_KEYS);

            $httpMethod = ArtifactValidation::string($route, 'Router route', 'httpMethod');
            $pathTemplate = ArtifactValidation::string($route, 'Router route', 'pathTemplate');
            $controllerClass = ArtifactValidation::string($route, 'Router route', 'controllerClass');
            $controllerMethod = ArtifactValidation::string($route, 'Router route', 'controllerMethod');
            $status = ArtifactValidation::int($route, 'Router route', 'status');
            $middleware = ArtifactValidation::listOfStrings($route, 'Router route', 'middleware');

            try {
                /** @var class-string $controllerClass */
                $newRoute = new Route(
                    httpMethod: $httpMethod,
                    pathTemplate: $pathTemplate,
                    controllerClass: $controllerClass,
                    controllerMethod: $controllerMethod,
                    status: $status,
                    middleware: $middleware,
                );

                // Deliberately does NOT re-run register()'s own eager
                // Dispatcher::derivePlan() validation here: doing so would
                // reflect (and so autoload) every cached route's
                // controller class unconditionally, undoing the exact
                // performance property this whole compiled-cache path
                // exists for — only the controller actually dispatched to
                // is ever supposed to be touched. BootSequenceCacheTest's
                // own fixtures rely on this directly, using placeholder
                // controller classes that don't exist as real classes at
                // all, to prove a route reconstructed here is never
                // reflected. Trusted instead, the same way this method
                // already trusts the artifact wasn't hand-corrupted for
                // other invariants register() enforces live but
                // fromArray() doesn't re-derive: a route this check would
                // reject could never have been written by this project's
                // own Kinetis\Cache\Compiler in the first place, since
                // compile() discovers every route to compile via
                // register() itself.
                //
                // The same conflict check register() applies at
                // registration time — a compiled artifact carrying two
                // routes claiming exactly the same requests bypasses
                // that live invariant otherwise, silently reintroducing
                // the first-match-wins ambiguity register() exists to
                // reject.
                $key = $newRoute->conflictKey();
                $existing = $router->routesByConflictKey[$key] ?? null;

                if ($existing !== null) {
                    throw DuplicateRouteException::forConflict($existing, $newRoute);
                }

                $router->routesByConflictKey[$key] = $newRoute;
                $router->routes[] = $newRoute;
                // null, not a real signature: a compiled route's data
                // carries no record of the $globalMiddleware context its
                // controller was originally registered under, so there is
                // nothing to compare a later live register() call
                // against — see $registrationContexts's own doc comment.
                // As far as the serialized data permits: a controller
                // contributing zero routes was never distinguishable
                // from one never registered at all, in either path.
                $router->registrationContexts[$controllerClass] = null;
            } catch (Throwable $e) {
                if ($e instanceof CacheArtifactExceptionInterface) {
                    throw $e;
                }

                throw InvalidCacheArtifactException::malformedEntry('Router route', $e->getMessage());
            }
        }

        usort($router->routes, Route::compareForMatching(...));

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
