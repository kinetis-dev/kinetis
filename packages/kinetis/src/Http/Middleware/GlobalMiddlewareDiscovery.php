<?php

declare(strict_types=1);

namespace Kinetis\Http\Middleware;

use Kinetis\Cache\NamespaceScanner;
use Kinetis\Http\Attributes\AsGlobalMiddleware;
use Kinetis\Http\Attributes\AsMcpMiddleware;
use Kinetis\Http\Attributes\AsOpenApiMiddleware;
use ReflectionClass;

/**
 * Finds every class anywhere under a project's own PSR-4 root(s) — plus
 * Kinetis\Http itself, for symmetry with RouteDiscovery/CommandDiscovery/
 * McpDiscovery — carrying #[AsGlobalMiddleware], #[AsMcpMiddleware], or
 * #[AsOpenApiMiddleware], returning each as its own class-string list,
 * already sorted: priority descending, ties broken alphabetically by
 * fully-qualified class name so the result never depends on filesystem/
 * scan order. discoverAll() performs exactly one project-wide scan and
 * buckets by attribute — added once a security review surfaced a real
 * need for the narrower two (a way to protect /mcp or /openapi.json/
 * /docs specifically, without middleware also running on every other
 * route), so a class can be discovered as any combination of the three
 * without tripling the filesystem walk. discover() is the pre-existing,
 * single-purpose entry point kept for any caller that only ever wanted
 * the global list — still exactly one scan when called alone.
 *
 * Route middleware (#[Middleware], Http\Attributes\Middleware) has no
 * equivalent priority/tiebreak scheme, deliberately: it's read straight
 * off ReflectionClass/ReflectionMethod::getAttributes(), which PHP
 * already returns in source declaration order — an explicit,
 * unambiguous ordering with no tie to break. A priority concept only
 * exists here because discovery has no such declaration order to lean
 * on in the first place.
 *
 * Kinetis's own built-in middleware (CorsMiddleware, RateLimitMiddleware,
 * ...) is never attributed this way — each needs app-specific
 * constructor config (allowed origins, a limit) no default could supply,
 * so they stay opt-in via AppScope::middleware()/mcpMiddleware()/
 * openApiMiddleware() only.
 *
 * $paths restricts the project-wide scan to one or more sub-paths
 * (relative to each PSR-4 base directory) instead of scanning the whole
 * project. Falls back to the MIDDLEWARE_DISCOVERY_PATHS environment
 * variable (comma-separated) when not passed explicitly, so a project
 * can commit this restriction without every call site needing to know
 * about it.
 */
final class GlobalMiddlewareDiscovery
{
    /**
     * @param list<string>|null $paths
     * @return list<class-string>
     */
    public static function discover(string $projectRoot, ?array $paths = null): array
    {
        return self::discoverAll($projectRoot, $paths)['global'];
    }

    /**
     * @param list<string>|null $paths
     * @return array{global: list<class-string>, mcp: list<class-string>, openApi: list<class-string>}
     */
    public static function discoverAll(string $projectRoot, ?array $paths = null): array
    {
        $candidates = [
            ...NamespaceScanner::classesInProject($projectRoot, $paths ?? self::pathsFromEnv()),
            ...NamespaceScanner::classesUnderFrameworkSegment('Http'),
        ];

        /** @var array<class-string, int> $global */
        $global = [];
        /** @var array<class-string, int> $mcp */
        $mcp = [];
        /** @var array<class-string, int> $openApi */
        $openApi = [];

        foreach ($candidates as $class) {
            $reflection = new ReflectionClass($class);

            $globalAttributes = $reflection->getAttributes(AsGlobalMiddleware::class);
            if ($globalAttributes !== []) {
                $global[$class] = $globalAttributes[0]->newInstance()->priority;
            }

            $mcpAttributes = $reflection->getAttributes(AsMcpMiddleware::class);
            if ($mcpAttributes !== []) {
                $mcp[$class] = $mcpAttributes[0]->newInstance()->priority;
            }

            $openApiAttributes = $reflection->getAttributes(AsOpenApiMiddleware::class);
            if ($openApiAttributes !== []) {
                $openApi[$class] = $openApiAttributes[0]->newInstance()->priority;
            }
        }

        return [
            'global' => self::sortedByPriority($global),
            'mcp' => self::sortedByPriority($mcp),
            'openApi' => self::sortedByPriority($openApi),
        ];
    }

    /**
     * @param array<class-string, int> $priorities
     * @return list<class-string>
     */
    private static function sortedByPriority(array $priorities): array
    {
        $classes = array_keys($priorities);

        usort(
            $classes,
            static fn (string $a, string $b): int => $priorities[$b] <=> $priorities[$a] ?: $a <=> $b,
        );

        return $classes;
    }

    /**
     * @return list<string>
     */
    private static function pathsFromEnv(): array
    {
        $value = getenv('MIDDLEWARE_DISCOVERY_PATHS');

        if ($value === false || $value === '') {
            return [];
        }

        return array_map('trim', explode(',', $value));
    }
}
