<?php

declare(strict_types=1);

namespace Kinetis\Http\Routing;

use Kinetis\Cache\NamespaceScanner;

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
 */
final class RouteDiscovery
{
    /**
     * @param list<string>|null $paths
     */
    public static function discover(string $projectRoot, ?array $paths = null): Router
    {
        $router = new Router();

        // Deduped across both passes: when the project root and framework
        // root are the same repository, a class under Kinetis\Http can
        // surface from both classesInProject() and
        // classesUnderFrameworkSegment(). Router::register() has no
        // duplicate-name check, so without this a route would register
        // twice.
        /** @var array<class-string, true> $seen */
        $seen = [];

        foreach ([
            ...NamespaceScanner::classesInProject($projectRoot, $paths ?? self::pathsFromEnv()),
            ...NamespaceScanner::classesUnderFrameworkSegment('Http'),
        ] as $class) {
            if (isset($seen[$class])) {
                continue;
            }

            $seen[$class] = true;
            $router->register($class);
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
