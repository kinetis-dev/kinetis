<?php

declare(strict_types=1);

namespace Kinetis\Cache;

use Kinetis\Container\AppScope;

/**
 * The runtime half of the pluggable AOT-cache mechanism — mirrors the
 * `null`-means-discover-live convention `RoutesFile::loadBootstrap()`'s
 * own `$packageBootstraps` parameter already establishes, so
 * `public/index.php`/`bin/kinetis` need only compute one nullable array
 * per environment branch and call `bind()`, identically either way.
 *
 * `discover()` is the live path: every installed package's declared
 * {@see CacheableDiscoveryInterface} class, called fresh — the same
 * method {@see Compiler::compileProject()} calls to build the shared
 * cache file in the first place, so there is exactly one algorithm
 * producing this data, not two.
 */
final class PluginDiscovery
{
    /**
     * @return array<class-string, array<array-key, mixed>>
     */
    public static function discover(string $projectRoot): array
    {
        $data = [];

        foreach (PackageDiscovery::discoveryClasses($projectRoot) as $class) {
            $data[$class] = $class::compile($projectRoot);
        }

        return $data;
    }

    /**
     * Reconstructs each entry via its own class's `fromArray()` and binds
     * it into `AppScope` directly — before the bootstrap chain runs, the
     * same timing `EventListenerRegistry` already gets. A package's own
     * `PackageBootstrapInterface::register()` never touches this: by the
     * time it runs, the binding already exists.
     *
     * $data null means "no precompiled data available" (development, or
     * production with nothing cached yet) — discovered live instead, the
     * exact tolerance `Hydrator`'s own binding plans already give a class
     * absent from a supplied plan map: the cache never has to be complete
     * for correctness.
     *
     * @param ?array<class-string, array<array-key, mixed>> $data
     */
    public static function bind(AppScope $app, string $projectRoot, ?array $data): void
    {
        foreach ($data ?? self::discover($projectRoot) as $class => $entry) {
            $app->instance($class, $class::fromArray($entry));
        }
    }
}
