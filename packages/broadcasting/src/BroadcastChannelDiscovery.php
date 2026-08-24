<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting;

use Kinetis\Cache\NamespaceScanner;
use Kinetis\Cache\PackageDiscovery;

/**
 * Mirrors `Kinetis\Mcp\McpDiscovery` exactly: an unrestricted scan of the
 * project's own PSR-4 roots (bounded by `BROADCAST_CHANNEL_DISCOVERY_PATHS`
 * when set, the same env-var convention `ROUTE_DISCOVERY_PATHS`/
 * `MCP_DISCOVERY_PATHS`/`LISTENER_DISCOVERY_PATHS` already establish),
 * plus Kinetis's own `Broadcasting` framework segment for symmetry (no
 * framework-internal class carries `#[BroadcastChannel]` today), plus
 * every installed package's own declared scan roots. Three passes can
 * find the same class twice — most concretely when developing this
 * monorepo itself, where the framework root and the project root are the
 * same repository — so results are deduplicated by class name before
 * `register()` ever runs, the fix every other Discovery class in this
 * project already carries for the identical reason.
 */
final class BroadcastChannelDiscovery
{
    /**
     * @param ?list<string> $paths
     */
    public static function discover(string $projectRoot, ?array $paths = null): BroadcastChannelRegistry
    {
        $registry = new BroadcastChannelRegistry();
        $seen = [];

        foreach ([
            ...NamespaceScanner::classesInProject($projectRoot, $paths ?? self::pathsFromEnv()),
            ...NamespaceScanner::classesUnderFrameworkSegment('Broadcasting'),
            ...NamespaceScanner::classesUnderPackageRoots(PackageDiscovery::scanRoots($projectRoot)),
        ] as $class) {
            if (isset($seen[$class])) {
                continue;
            }

            $seen[$class] = true;
            $registry->register($class);
        }

        return $registry;
    }

    /**
     * @return list<string>
     */
    private static function pathsFromEnv(): array
    {
        $raw = getenv('BROADCAST_CHANNEL_DISCOVERY_PATHS');

        if ($raw === false || $raw === '') {
            return [];
        }

        return array_map(trim(...), explode(',', $raw));
    }
}
