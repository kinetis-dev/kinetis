<?php

declare(strict_types=1);

namespace Kinetis\Console;

use Kinetis\Cache\NamespaceScanner;
use Kinetis\Cache\PackageDiscovery;

/**
 * Builds a CommandRegistry from every class found in a project — no
 * namespace/directory convention required — plus every class found under
 * Kinetis\Console specifically (framework-provided commands, e.g.
 * BuildCommand/McpServeCommand), rather than requiring an explicit
 * commands.php registration file. A discovered class with no
 * #[Command]-attributed method is a harmless no-op, the same as
 * CommandRegistry::register() already is for any other class.
 *
 * $paths restricts the project-wide scan to one or more sub-paths
 * (relative to each PSR-4 base directory) instead of scanning the whole
 * project — for an application large enough to want a bounded scan.
 * Falls back to the COMMAND_DISCOVERY_PATHS environment variable
 * (comma-separated) when not passed explicitly, so a project can commit
 * this restriction without every call site needing to know about it.
 */
final class CommandDiscovery
{
    /**
     * @param list<string>|null $paths
     */
    public static function discover(string $projectRoot, ?array $paths = null): CommandRegistry
    {
        $registry = new CommandRegistry();

        // Deduped across both passes: when the project root and framework
        // root are the same repository, a class under Kinetis\Console
        // can surface from both classesInProject() and
        // classesUnderFrameworkSegment(). CommandRegistry::register()
        // throws on a duplicate command name.
        /** @var array<class-string, true> $seen */
        $seen = [];

        foreach ([
            ...NamespaceScanner::classesInProject($projectRoot, $paths ?? self::pathsFromEnv()),
            ...NamespaceScanner::classesUnderFrameworkSegment('Console'),
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
        $value = getenv('COMMAND_DISCOVERY_PATHS');

        if ($value === false || $value === '') {
            return [];
        }

        return array_map('trim', explode(',', $value));
    }
}
