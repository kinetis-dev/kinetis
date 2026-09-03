<?php

declare(strict_types=1);

namespace Kinetis\Http\Routing;

use Kinetis\Cache\NamespaceScanner;
use Kinetis\Cache\PackageDiscovery;

/**
 * Builds a Router from every class found in a project — no namespace/
 * directory convention required — plus every class found under
 * Kinetis\Http specifically (framework internals only; none carry a
 * route attribute today, kept for symmetry with CommandDiscovery/
 * McpDiscovery), rather than requiring an explicit routes.php
 * registration file. A discovered class with no #[Get]/#[Post]/...
 * attributed method is a harmless no-op, the same as Router::register()
 * already is for any other class.
 *
 * $paths restricts the project-wide scan to one or more sub-paths
 * (relative to each PSR-4 base directory) instead of scanning the whole
 * project — for an application large enough to want a bounded scan.
 * Falls back to the ROUTE_DISCOVERY_PATHS environment variable
 * (comma-separated) when not passed explicitly, so a project can commit
 * this restriction without every call site needing to know about it.
 *
 * $globalMiddleware is passed straight through to every Router::register()
 * call — see that method's own doc comment for what it does with it. The
 * caller (public/index.php's dev branch, Kinetis\Cache\Compiler) is
 * responsible for discovering it first, via
 * GlobalMiddlewareDiscovery::discoverAll(), since this class has no
 * business knowing how a global middleware list is produced.
 *
 * No dedup bookkeeping here, deliberately: `Router::register()` is
 * itself idempotent per class now (a repeat registration is a safe
 * no-op), the same invariant `EventListenerDiscovery` already relies on
 * `EventListenerRegistry::register()` for rather than keeping a second,
 * external `$seen` set — the project root and framework root being the
 * same repository (developing Kinetis itself) is exactly the case that
 * makes a class under `Kinetis\Http` surface from both
 * `classesInProject()` and `classesUnderFrameworkSegment()`, and
 * `register()` alone is what makes that harmless now.
 */
final class RouteDiscovery
{
    /**
     * @param list<string>|null $paths
     * @param list<class-string> $globalMiddleware
     */
    public static function discover(string $projectRoot, ?array $paths = null, array $globalMiddleware = []): Router
    {
        $router = new Router();

        foreach ([
            ...NamespaceScanner::classesInProject($projectRoot, $paths ?? self::pathsFromEnv()),
            ...NamespaceScanner::classesUnderFrameworkSegment('Http'),
            ...NamespaceScanner::classesUnderPackageRoots(PackageDiscovery::scanRoots($projectRoot)),
        ] as $class) {
            $router->register($class, $globalMiddleware);
        }

        return $router;
    }

    /**
     * @return list<string>
     */
    private static function pathsFromEnv(): array
    {
        $value = getenv('ROUTE_DISCOVERY_PATHS');

        if ($value === false || $value === '') {
            return [];
        }

        return array_map('trim', explode(',', $value));
    }
}
