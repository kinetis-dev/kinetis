<?php

declare(strict_types=1);

namespace Kinetis\Cache;

use Kinetis\Cache\Exception\DiscoverySectionException;

/**
 * Everything one discovery operation shares: a development boot, a CLI
 * invocation, a `TestApplication` boot, a production fallback compile,
 * `kinetis build`, or `routes:list`'s own introspection pass. The entry
 * point creates one, hands it to every discoverer and discovery section
 * that operation runs, and drops it when the operation ends. Nothing
 * holds it past that: a worker keeps what discovery produced, never the
 * context, so the next boot sees the filesystem as it is then.
 *
 * It owns three inventories, each filled once:
 *
 * - the class scan ({@see NamespaceScanner}), memoized per namespace
 *   prefix and directory, so routes, middleware, listeners, commands and
 *   every section that walks the same root share one enumeration, token
 *   pass and reflection check;
 * - the installed-package inventory ({@see PackageDiscovery}), read from
 *   `vendor/composer/installed.json` once for scan roots, bootstrap
 *   classes and discovery sections alike;
 * - the compiled discovery sections, through {@see compiled()}.
 */
final class DiscoveryContext
{
    private readonly NamespaceScanner $scanner;

    private readonly PackageDiscovery $packages;

    /** @var array<class-string<CacheableDiscoveryInterface>, array<array-key, mixed>> */
    private array $compiled = [];

    /** @var list<class-string<CacheableDiscoveryInterface>> */
    private array $compiling = [];

    public function __construct(
        public readonly string $projectRoot,
    ) {
        $this->scanner = new NamespaceScanner($projectRoot);
        $this->packages = new PackageDiscovery($projectRoot);
    }

    /**
     * Classes under the project's own `autoload.psr-4` roots — see
     * {@see NamespaceScanner::classesInProject()}.
     *
     * @param list<string> $paths sub-paths relative to each PSR-4 base
     *     directory; empty means the whole root
     * @return list<class-string>
     */
    public function projectClasses(array $paths = []): array
    {
        return $this->scanner->classesInProject($paths);
    }

    /**
     * Classes under one namespace segment of Kinetis's own package root
     * (`Http`, `Console`, `Mcp`, ...).
     *
     * @return list<class-string>
     */
    public function frameworkClasses(string $segment): array
    {
        return $this->scanner->classesUnderFrameworkSegment($segment);
    }

    /**
     * Classes under the roots installed packages declare through
     * `extra.kinetis.scan`.
     *
     * @return list<class-string>
     */
    public function packageClasses(): array
    {
        return $this->scanner->classesUnderPackageRoots($this->packages->scanRoots());
    }

    /**
     * @return list<class-string>
     */
    public function packageBootstraps(): array
    {
        return $this->packages->bootstrapClasses();
    }

    /**
     * Installed discovery sections, in Composer's recorded order.
     *
     * @return list<class-string<CacheableDiscoveryInterface>>
     */
    public function discoverySections(): array
    {
        return $this->packages->discoveryClasses();
    }

    /**
     * One installed discovery section's compiled data, compiled on the
     * first read and returned as the same plain array on every later one.
     *
     * A section that consumes another reads it here from its own
     * `compile()`, so the upstream section compiles before its consumer
     * finishes and exactly once, whichever of them
     * {@see PluginDiscovery::discover()} reaches first. The consumer gets
     * data, never a reconstructed instance: `fromArray()` is
     * construction, and only a boot constructs.
     *
     * @param class-string<CacheableDiscoveryInterface> $section
     * @return array<array-key, mixed>
     * @throws DiscoverySectionException when no installed package
     *     declares $section, or when compiling it requires itself
     */
    public function compiled(string $section): array
    {
        if (array_key_exists($section, $this->compiled)) {
            return $this->compiled[$section];
        }

        if (in_array($section, $this->compiling, true)) {
            throw DiscoverySectionException::cycle([...$this->compiling, $section]);
        }

        if (!in_array($section, $this->packages->discoveryClasses(), true)) {
            throw DiscoverySectionException::notInstalled(
                $section,
                $this->compiling === [] ? null : $this->compiling[array_key_last($this->compiling)],
                $this->packages->skippedDiscoveryDeclaration($section),
            );
        }

        $this->compiling[] = $section;

        try {
            $data = $section::compile($this);
        } finally {
            array_pop($this->compiling);
        }

        return $this->compiled[$section] = $data;
    }
}
