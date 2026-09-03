<?php

declare(strict_types=1);

namespace Kinetis\Cache;

use FilesystemIterator;
use Kinetis\Reflection\AttributeScope;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Finds classes to feed attribute-driven auto-discovery
 * (Kinetis\Http\Routing\RouteDiscovery, Kinetis\Console\CommandDiscovery,
 * Kinetis\Mcp\McpDiscovery) two different ways:
 *
 * - classesInProject() walks every PSR-4 prefix a project's own
 *   composer.json declares, at any depth, with no namespace/directory
 *   convention required — a project organizes its own classes however it
 *   wants. $paths optionally restricts this to one or more sub-paths
 *   (relative to each PSR-4 base directory) instead, for a project large
 *   enough to want a bounded scan.
 * - classesUnderFrameworkSegment() walks one fixed namespace segment
 *   under Kinetis's own package root specifically ("Console", "Mcp") —
 *   the framework's own discoverable classes live in exactly one place
 *   each, so this stays segment-scoped rather than scanning core's
 *   entire, much larger tree for a handful of matches.
 *
 * Never reflects/autoloads a class outright: classesInDirectory() first
 * checks whether a file contains *any* PHP attribute at all (a cheap
 * token scan, not a full parse) before ever `class_exists()`-triggering
 * the real autoload+compile+reflect chain — most classes in a real
 * application (plain DTOs, services, repositories, ...) carry no
 * attribute whatsoever and are skipped without ever being loaded. This is
 * what keeps an unrestricted, whole-project scan affordable.
 *
 * Reads composer.json's autoload.psr-4 directly rather than the runtime
 * Composer ClassLoader — getPrefixesPsr4() merges in every installed
 * dependency's own PSR-4 mappings too, which would turn "scan the
 * project's own classes" into "scan half of vendor/" and defeat the
 * entire point.
 *
 * A class found this way is expected to already be autoloadable through
 * the project's own registered Composer autoloader — this only ever
 * derives *names* from the filesystem layout PSR-4 itself implies, it
 * never require()s a file directly, so the normal autoloader (already
 * registered by the time this runs, in every real entry point) is what
 * actually loads each one.
 */
final class NamespaceScanner
{
    /**
     * @param list<string> $paths One or more sub-paths, relative to each
     *     PSR-4 base directory, to restrict the walk to. Empty (the
     *     default) means the whole PSR-4 root, unrestricted.
     * @return iterable<class-string>
     */
    public static function classesInProject(string $projectRoot, array $paths = []): iterable
    {
        self::warnIfNoPsr4Root($projectRoot);

        /** @var array<class-string, true> $seen */
        $seen = [];

        foreach (self::classesUnderRoot($projectRoot, $paths) as $class) {
            if (isset($seen[$class])) {
                continue;
            }

            $seen[$class] = true;

            yield $class;
        }
    }

    /**
     * A missing/empty `autoload.psr-4` map means discovery will silently
     * find nothing, ever — without this warning, that's a plain 404 on a
     * project's very first request with zero explanation
     * (the tutorial reproduces exactly this). Surfaced
     * via error_log() rather than a real PSR-3 LoggerInterface: every
     * caller of classesInProject() (RouteDiscovery, CommandDiscovery,
     * McpDiscovery, ...) is a pure static function with no container
     * access to resolve one from, the same constraint that keeps this
     * whole class stateless.
     */
    private static function warnIfNoPsr4Root(string $projectRoot): void
    {
        $composerJsonPath = $projectRoot . '/composer.json';

        if (is_file($composerJsonPath) && self::resolvePsr4Map($composerJsonPath) !== []) {
            return;
        }

        error_log(
            "Kinetis\\Cache\\NamespaceScanner found no PSR-4 root to scan under \"{$projectRoot}\" — "
            . 'did you forget an "autoload": {"psr-4": ...} entry in composer.json? '
            . 'Route/command/tool/middleware/listener discovery will find nothing until one exists.',
        );
    }

    /**
     * $frameworkRoot is accepted as an optional parameter for the same
     * testability reason as AppEnvironment::detect()/ProjectRoot::detect()
     * — exercising this against a fixture instead of this repo's own real
     * src/ tree.
     *
     * @return iterable<class-string>
     */
    public static function classesUnderFrameworkSegment(string $segment, ?string $frameworkRoot = null): iterable
    {
        yield from self::classesUnderRoot($frameworkRoot ?? self::defaultFrameworkRoot(), [$segment]);
    }

    /**
     * Walks the prefix/directory pairs installed packages offer for
     * discovery (see {@see PackageDiscovery::scanRoots()}) — the third
     * class source next to the project scan and the framework segment,
     * with the same attribute pre-filter applied per file.
     *
     * @param list<array{prefix: string, directory: string}> $roots
     * @return iterable<class-string>
     */
    public static function classesUnderPackageRoots(array $roots): iterable
    {
        foreach ($roots as $root) {
            yield from self::classesInDirectory(rtrim($root['prefix'], '\\') . '\\', $root['directory']);
        }
    }

    /**
     * Kinetis's own package root — src/ under a "Kinetis\\" prefix,
     * regardless of whether that's this monorepo (developing Kinetis
     * itself) or vendor/kinetis/framework (installed as a dependency). Same
     * dirname(__DIR__, 2) trick Kinetis\Mcp\KinetisDocsResource already
     * uses to find its own package root from src/Mcp/.
     */
    private static function defaultFrameworkRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @param list<string> $paths
     * @return iterable<class-string>
     */
    private static function classesUnderRoot(string $root, array $paths): iterable
    {
        $composerJsonPath = $root . '/composer.json';

        if (!is_file($composerJsonPath)) {
            return;
        }

        foreach (self::resolvePsr4Map($composerJsonPath) as $prefix => $psr4Paths) {
            if (!is_string($prefix)) {
                continue;
            }

            foreach ((is_array($psr4Paths) ? $psr4Paths : [$psr4Paths]) as $psr4Path) {
                if (!is_string($psr4Path)) {
                    continue;
                }

                yield from self::classesUnderPsr4Path($root, $prefix, $psr4Path, $paths);
            }
        }
    }

    /**
     * @param list<string> $paths
     * @return iterable<class-string>
     */
    private static function classesUnderPsr4Path(string $root, string $prefix, string $psr4Path, array $paths): iterable
    {
        if ($paths === []) {
            yield from self::classesInDirectory(
                rtrim($prefix, '\\') . '\\',
                self::joinPath($root, $psr4Path, null),
            );

            return;
        }

        foreach ($paths as $path) {
            yield from self::classesInDirectory(
                rtrim($prefix, '\\') . '\\' . trim($path, '/') . '\\',
                self::joinPath($root, $psr4Path, $path),
            );
        }
    }

    /**
     * @return array<mixed, mixed>
     */
    private static function resolvePsr4Map(string $composerJsonPath): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode((string) file_get_contents($composerJsonPath), associative: true);

        return is_array($decoded) && is_array($decoded['autoload'] ?? null) && is_array($decoded['autoload']['psr-4'] ?? null)
            ? $decoded['autoload']['psr-4']
            : [];
    }

    private static function joinPath(string $root, string $psr4Path, ?string $path): string
    {
        $base = rtrim($root . '/' . trim($psr4Path, '/'), '/');

        return $path === null ? $base : $base . '/' . trim($path, '/');
    }

    /**
     * Sorted by pathname before yielding a single class name — POSIX
     * gives no ordering guarantee for a directory's own readdir()
     * result, and it can genuinely differ between filesystems (a bind
     * mount vs. a native one, for instance). Every caller of this class
     * (RouteDiscovery, CommandDiscovery, McpDiscovery,
     * EventListenerDiscovery, GlobalMiddlewareDiscovery) needs discovery
     * order to be reproducible across environments, not just within one
     * — a plain directory walk has nothing else to sort by until every
     * file's own path is known, so the whole subtree is enumerated up
     * front rather than yielded lazily as it's found.
     *
     * @return iterable<class-string>
     */
    private static function classesInDirectory(string $namespacePrefix, string $directory): iterable
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        $paths = [];

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }

        sort($paths);

        foreach ($paths as $path) {
            if (!self::fileHasAnyAttribute($path)) {
                continue;
            }

            $relative = substr($path, strlen($directory) + 1, -4);
            $className = $namespacePrefix . str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

            // Discovery skips what registration would reject: an abstract
            // base or an enum under a scanned namespace is not an error,
            // it just isn't a candidate. AttributeScope::reflect() is what
            // fails loudly when one is registered by name instead.
            if (AttributeScope::isRegistrable($className)) {
                yield $className;
            }
        }
    }

    /**
     * A cheap pre-filter: does this file use PHP attribute syntax at all,
     * anywhere? Tokenizing is far cheaper than autoloading — it never
     * resolves `use` statements, parent classes, interfaces, or compiles
     * anything — so this skips the expensive class_exists() autoload
     * entirely for a file that couldn't possibly carry the attribute
     * being searched for. Deliberately coarse (checks for *any* T_ATTRIBUTE
     * token, not the specific attribute class in question): resolving which
     * imported alias a bare `#[Get]` actually refers to would need real
     * `use`-statement parsing, and the cost of occasionally reflecting a
     * class with an unrelated attribute is negligible next to the cost of
     * ever missing a real match.
     */
    private static function fileHasAnyAttribute(string $path): bool
    {
        $source = file_get_contents($path);

        if ($source === false) {
            return false;
        }

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && $token[0] === T_ATTRIBUTE) {
                return true;
            }
        }

        return false;
    }
}
