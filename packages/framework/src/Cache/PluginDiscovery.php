<?php

declare(strict_types=1);

namespace Kinetis\Cache;

use Kinetis\Cache\Exception\CacheArtifactExceptionInterface;
use Kinetis\Cache\Exception\InvalidCacheArtifactException;
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
     * Reconstructs every entry via its own class's `fromArray()` — the
     * one reconstruction algorithm `bind()` below and
     * `BootSequence`'s cache-bundle validation both use, rather than
     * each repeating this loop. Propagates whatever a class's own
     * `fromArray()` throws for malformed data unchanged: this method has
     * no fallback of its own to offer, so the caller decides what a
     * failure means (a cache-bundle load treats it as corruption and
     * falls back to a fresh compile; `bind()` below simply lets it
     * propagate, since a package's own `fromArray()` failing against
     * data it *itself* just produced via `compile()` — the live path —
     * is a real bug, not something to paper over).
     *
     * The map itself is validated before any dynamic dispatch, the same
     * discipline `EventListenerRegistry::fromArray()` already applies to
     * its own compiled map: every key must be a real string (PHP
     * silently coerces a numeric-looking array key to int) shaped like
     * a class-string that both exists and implements
     * `CacheableDiscoveryInterface`, and every entry must itself be an
     * array — each a classified `InvalidCacheArtifactException`, not a
     * raw fatal from calling a static method on a non-class string.
     *
     * @param array<array-key, mixed> $data
     * @return array<class-string, object>
     * @throws CacheArtifactExceptionInterface
     */
    public static function reconstruct(array $data): array
    {
        $instances = [];

        foreach ($data as $class => $entry) {
            if (!is_string($class) || !class_exists($class)) {
                throw InvalidCacheArtifactException::malformedEntry('PluginCache', 'a key that is not an existing class-string');
            }

            if (!is_a($class, CacheableDiscoveryInterface::class, true)) {
                throw InvalidCacheArtifactException::malformedEntry('PluginCache', "\"{$class}\" does not implement CacheableDiscoveryInterface");
            }

            if (!is_array($entry)) {
                throw InvalidCacheArtifactException::malformedEntry('PluginCache', "the entry for \"{$class}\" is not an array");
            }

            $instances[$class] = $class::fromArray($entry);
        }

        return $instances;
    }

    /**
     * Binds already-reconstructed instances into `AppScope` directly —
     * before the bootstrap chain runs, the same timing
     * `EventListenerRegistry` already gets. A package's own
     * `PackageBootstrapInterface::register()` never touches this: by the
     * time it runs, the binding already exists.
     *
     * The pure binding half of what `bind()` below does, split out so a
     * caller that already reconstructed instances once — `BootSequence::
     * run()`, given `resolveHttp()`/`resolveCli()`'s own already-
     * reconstructed result — never reconstructs them a second time.
     * `fromArray()` is object construction, not a guaranteed pure
     * validator: a second call could have side effects the first
     * already had, cost real construction time twice, or even fail
     * where the first succeeded.
     *
     * @param array<class-string, object> $instances
     */
    public static function bindInstances(AppScope $app, array $instances): void
    {
        foreach ($instances as $class => $instance) {
            $app->instance($class, $instance);
        }
    }

    /**
     * Reconstructs each entry (via `reconstruct()` above) and binds it
     * via `bindInstances()`. A convenience for a caller with raw data
     * (or none) that doesn't need reconstruct-once-reuse-twice — see
     * `bindInstances()` for the caller that does.
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
        self::bindInstances($app, self::reconstruct($data ?? self::discover($projectRoot)));
    }
}
