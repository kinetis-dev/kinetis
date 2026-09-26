<?php

declare(strict_types=1);

namespace Kinetis\Cache;

use FilesystemIterator;
use Kinetis\Reflection\AttributeScope;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The class scan one {@see DiscoveryContext} owns, feeding attribute-driven
 * discovery (RouteDiscovery, CommandDiscovery, McpDiscovery, ...) from
 * three sources:
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
 * - classesUnderPackageRoots() walks the prefix/directory pairs installed
 *   packages offer (see {@see PackageDiscovery::scanRoots()}).
 *
 * All three reduce to the same physical unit: one namespace prefix paired
 * with one directory. Each pair is enumerated, token-filtered and
 * reflected once per instance, and every later request for it returns
 * that first result, so every discoverer in one operation that asks for
 * the whole project shares one walk. A different sub-path restriction is
 * a different pair, walked on its own.
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
 *
 * @internal Owned by {@see DiscoveryContext}; discoverers read classes through it.
 */
final class NamespaceScanner
{
    /** @var array<string, list<class-string>> keyed by namespace prefix and directory */
    private array $scanned = [];

    /** @var array<string, array<mixed, mixed>> keyed by root */
    private array $psr4Maps = [];

    private bool $warnedNoPsr4Root = false;

    public function __construct(
        private readonly string $projectRoot,
    ) {}

    /**
     * @param list<string> $paths One or more sub-paths, relative to each
     *     PSR-4 base directory, to restrict the walk to. Empty (the
     *     default) means the whole PSR-4 root, unrestricted.
     * @return list<class-string>
     */
    public function classesInProject(array $paths = []): array
    {
        $this->warnIfNoPsr4Root();

        /** @var array<class-string, true> $seen */
        $seen = [];

        foreach ($this->classesUnderRoot($this->projectRoot, $paths) as $class) {
            $seen[$class] = true;
        }

        return array_keys($seen);
    }

    /**
     * A missing/empty `autoload.psr-4` map means discovery will silently
     * find nothing, ever — without this warning, that's a plain 404 on a
     * project's very first request with zero explanation
     * (the tutorial reproduces exactly this). Surfaced via error_log()
     * rather than a real PSR-3 LoggerInterface: discovery runs before the
     * container is booted, so there is no logger to resolve yet. Reported
     * once per operation, however many discoverers ask.
     */
    private function warnIfNoPsr4Root(): void
    {
        if ($this->warnedNoPsr4Root || $this->psr4Map($this->projectRoot) !== []) {
            return;
        }

        $this->warnedNoPsr4Root = true;

        error_log(
            "Kinetis\\Cache\\NamespaceScanner found no PSR-4 root to scan under \"{$this->projectRoot}\" — "
            . 'did you forget an "autoload": {"psr-4": ...} entry in composer.json? '
            . 'Route/command/tool/middleware/listener discovery will find nothing until one exists.',
        );
    }

    /**
     * @return list<class-string>
     */
    public function classesUnderFrameworkSegment(string $segment): array
    {
        return $this->classesUnderRoot(self::frameworkRoot(), [$segment]);
    }

    /**
     * @param list<array{prefix: string, directory: string}> $roots
     * @return list<class-string>
     */
    public function classesUnderPackageRoots(array $roots): array
    {
        $classes = [];

        foreach ($roots as $root) {
            array_push($classes, ...$this->classesInDirectory($root['prefix'], $root['directory']));
        }

        return $classes;
    }

    /**
     * Kinetis's own package root — src/ under a "Kinetis\\" prefix,
     * regardless of whether that's this monorepo (developing Kinetis
     * itself) or vendor/kinetis/framework (installed as a dependency).
     */
    private static function frameworkRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @param list<string> $paths
     * @return list<class-string>
     */
    private function classesUnderRoot(string $root, array $paths): array
    {
        $classes = [];

        foreach ($this->psr4Map($root) as $prefix => $psr4Paths) {
            if (!is_string($prefix)) {
                continue;
            }

            foreach ((is_array($psr4Paths) ? $psr4Paths : [$psr4Paths]) as $psr4Path) {
                if (!is_string($psr4Path)) {
                    continue;
                }

                array_push($classes, ...$this->classesUnderPsr4Path($root, $prefix, $psr4Path, $paths));
            }
        }

        return $classes;
    }

    /**
     * @param list<string> $paths
     * @return list<class-string>
     */
    private function classesUnderPsr4Path(string $root, string $prefix, string $psr4Path, array $paths): array
    {
        if ($paths === []) {
            return $this->classesInDirectory($prefix, self::joinPath($root, $psr4Path, null));
        }

        $classes = [];

        foreach ($paths as $path) {
            array_push($classes, ...$this->classesInDirectory(
                rtrim($prefix, '\\') . '\\' . trim($path, '/'),
                self::joinPath($root, $psr4Path, $path),
            ));
        }

        return $classes;
    }

    /**
     * The root's `autoload.psr-4` map, read once; empty when the root has
     * no composer.json or the map is absent.
     *
     * @return array<mixed, mixed>
     */
    private function psr4Map(string $root): array
    {
        if (array_key_exists($root, $this->psr4Maps)) {
            return $this->psr4Maps[$root];
        }

        $composerJsonPath = $root . '/composer.json';
        /** @var mixed $decoded */
        $decoded = is_file($composerJsonPath)
            ? json_decode((string) file_get_contents($composerJsonPath), associative: true)
            : null;

        return $this->psr4Maps[$root] = is_array($decoded) && is_array($decoded['autoload'] ?? null) && is_array($decoded['autoload']['psr-4'] ?? null)
            ? $decoded['autoload']['psr-4']
            : [];
    }

    private static function joinPath(string $root, string $psr4Path, ?string $path): string
    {
        $base = rtrim($root . '/' . trim($psr4Path, '/'), '/');

        return $path === null ? $base : $base . '/' . trim($path, '/');
    }

    /**
     * The physical scan unit, memoized by its normalized prefix and
     * directory: a pair already walked returns its first result without
     * touching the filesystem again.
     *
     * Sorted by pathname before deriving a single class name — POSIX
     * gives no ordering guarantee for a directory's own readdir()
     * result, and it can genuinely differ between filesystems (a bind
     * mount vs. a native one, for instance). Every discoverer needs
     * discovery order to be reproducible across environments, not just
     * within one — a plain directory walk has nothing else to sort by
     * until every file's own path is known, so the whole subtree is
     * enumerated up front.
     *
     * @return list<class-string>
     */
    private function classesInDirectory(string $namespacePrefix, string $directory): array
    {
        $namespacePrefix = rtrim($namespacePrefix, '\\') . '\\';
        $directory = rtrim($directory, '/');
        $key = $namespacePrefix . "\0" . $directory;

        return $this->scanned[$key] ??= self::scanDirectory($namespacePrefix, $directory);
    }

    /**
     * @return list<class-string>
     */
    private static function scanDirectory(string $namespacePrefix, string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
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

        $classes = [];

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
                $classes[] = $className;
            }
        }

        return $classes;
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
     *
     * The raw "#[" byte check ahead of it is a negative-only guard: its
     * absence proves T_ATTRIBUTE can't appear, so tokenizing is skipped
     * outright. Its presence proves nothing (a comment or string literal
     * can contain "#[" without any attribute existing) and always falls
     * through to the real tokenizer below.
     */
    private static function fileHasAnyAttribute(string $path): bool
    {
        $source = file_get_contents($path);

        if ($source === false || !str_contains($source, '#[')) {
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
