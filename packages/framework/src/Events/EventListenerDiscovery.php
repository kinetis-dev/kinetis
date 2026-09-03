<?php

declare(strict_types=1);

namespace Kinetis\Events;

use Kinetis\Cache\NamespaceScanner;
use Kinetis\Cache\PackageDiscovery;

/**
 * Builds an EventListenerRegistry from every class found anywhere under a
 * project's own PSR-4 root(s) — plus Kinetis\Events itself, for symmetry
 * with RouteDiscovery/CommandDiscovery/McpDiscovery/GlobalMiddlewareDiscovery,
 * though nothing under Kinetis\Events carries #[Listener] today — rather
 * than requiring an explicit bootstrap.php registration. A discovered
 * class with no #[Listener]-attributed method is a harmless no-op, the
 * same as EventListenerRegistry::register() already is for any other
 * class.
 *
 * $paths restricts the project-wide scan to one or more sub-paths
 * (relative to each PSR-4 base directory) instead of scanning the whole
 * project. Falls back to the LISTENER_DISCOVERY_PATHS environment
 * variable (comma-separated) when not passed explicitly, so a project
 * can commit this restriction without every call site needing to know
 * about it.
 */
final class EventListenerDiscovery
{
    /**
     * @param list<string>|null $paths
     */
    public static function discover(string $projectRoot, ?array $paths = null): EventListenerRegistry
    {
        $registry = new EventListenerRegistry();

        // No deduplication needed here — a class can genuinely surface
        // from more than one of these three passes (a project root and
        // the framework root being the same repository, for instance),
        // and EventListenerRegistry::register() is itself idempotent per
        // class, so a repeated name across sources is simply a no-op on
        // its second and later occurrences.
        foreach ([
            ...NamespaceScanner::classesInProject($projectRoot, $paths ?? self::pathsFromEnv()),
            ...NamespaceScanner::classesUnderFrameworkSegment('Events'),
            ...NamespaceScanner::classesUnderPackageRoots(PackageDiscovery::scanRoots($projectRoot)),
        ] as $class) {
            $registry->register($class);
        }

        return $registry;
    }

    /**
     * @return list<string>
     */
    private static function pathsFromEnv(): array
    {
        $value = getenv('LISTENER_DISCOVERY_PATHS');

        if ($value === false || $value === '') {
            return [];
        }

        return array_map('trim', explode(',', $value));
    }
}
