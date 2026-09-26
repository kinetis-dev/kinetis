<?php

declare(strict_types=1);

namespace Kinetis\Cache;

use Kinetis\Cache\Exception\CacheArtifactExceptionInterface;
use Kinetis\Cache\Exception\InvalidCacheArtifactException;
use Kinetis\Container\AppScope;

/**
 * The runtime half of the pluggable AOT-cache mechanism.
 *
 * `discover()` compiles every installed package's declared
 * {@see CacheableDiscoveryInterface} section through one
 * {@see DiscoveryContext} — the same method {@see Compiler::compileProject()}
 * calls to build the shared cache file, so there is exactly one algorithm
 * producing this data, not two. `reconstruct()` turns that data, fresh or
 * read back from the artifact, into live instances, and
 * `bindInstances()` binds them.
 */
final class PluginDiscovery
{
    /**
     * Every installed section's compiled data, keyed by section class in
     * Composer's recorded order. Each is obtained through
     * {@see DiscoveryContext::compiled()}, so a section another one
     * already read is not compiled again, and a section's dependencies
     * finish compiling before it does, whatever Composer's order.
     *
     * @return array<class-string<CacheableDiscoveryInterface>, array<array-key, mixed>>
     */
    public static function discover(DiscoveryContext $context): array
    {
        $data = [];

        foreach ($context->discoverySections() as $class) {
            $data[$class] = $context->compiled($class);
        }

        return $data;
    }

    /**
     * Reconstructs every entry via its own class's `fromArray()` — the
     * one reconstruction algorithm every live development boot and
     * `BootSequence`'s cache-bundle validation both use, rather than
     * each repeating this loop. Propagates whatever a class's own
     * `fromArray()` throws for malformed data unchanged: this method has
     * no fallback of its own to offer, so the caller decides what a
     * failure means (a cache-bundle load treats it as corruption and
     * falls back to a fresh compile; a development boot simply lets it
     * propagate, since a package's own `fromArray()` failing against
     * data it *itself* just produced via `compile()` is a real bug, not
     * something to paper over).
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
     * Binding only, never reconstruction: `fromArray()` is object
     * construction, not a guaranteed pure validator, so the caller that
     * reconstructed instances once — `BootSequence::run()`, given
     * `resolveHttp()`/`resolveCli()`'s result or a development boot's
     * `reconstruct()` — binds exactly those.
     *
     * @param array<class-string, object> $instances
     */
    public static function bindInstances(AppScope $app, array $instances): void
    {
        foreach ($instances as $class => $instance) {
            $app->instance($class, $instance);
        }
    }
}
