<?php

declare(strict_types=1);

namespace Kinetis\Cache;

/**
 * A package's own compile-time-discoverable, cacheable data — declared
 * once, via `extra.kinetis`'s `discovery` key (see
 * {@see PackageDiscovery::discoveryClasses()}), naming the implementing
 * class itself.
 *
 * The package supplies only these two static methods; everything else —
 * finding the declaration, compiling it into the shared cache file,
 * loading it back, and binding the reconstructed instance into
 * `AppScope` — is the framework's own job (see {@see PluginDiscovery}
 * and {@see Compiler}). A package's own `PackageBootstrapInterface`
 * implementation never touches this data at all: by the time it runs,
 * the framework has already bound it.
 */
interface CacheableDiscoveryInterface
{
    /**
     * Runs live discovery and returns plain, `var_export()`-safe data —
     * no objects, no closures. Called once per discovery operation, by
     * {@see DiscoveryContext::compiled()} only: `Compiler` to build the
     * shared cache file, and `PluginDiscovery::discover()` for a
     * development boot, both go through it.
     *
     * `$context` is the operation's own. Scan classes through it
     * (`projectClasses()`, `frameworkClasses()`, `packageClasses()`) to
     * share the walk every other discoverer in the operation makes. A
     * section that consumes another section reads that section's
     * compiled data through it:
     *
     *     $upstream = $context->compiled(UpstreamSection::class);
     *
     * which compiles the upstream section first, once, and returns the
     * same array on every later read. The upstream section must be
     * declared by an installed package, and the reads must not form a
     * cycle; either failure throws
     * {@see \Kinetis\Cache\Exception\DiscoverySectionException} naming
     * the sections involved.
     *
     * @return array<array-key, mixed>
     */
    public static function compile(DiscoveryContext $context): array;

    /**
     * Reconstructs a live instance from `compile()`'s own output —
     * whether that data just came from a fresh `compile()` call or was
     * loaded back out of a `var_export()`'d cache file, the result must
     * be identical either way.
     *
     * When `$data` does not represent a valid instance, must throw
     * something implementing
     * {@see \Kinetis\Cache\Exception\CacheArtifactExceptionInterface} —
     * this is the one exception category `BootSequence`'s cache-bundle
     * loaders classify as "the cache is stale or corrupt, compile
     * fresh instead." Anything else this method throws (an undefined
     * method call, a dependency failure, an assertion — a genuine
     * defect in this implementation, not a data-shape problem)
     * propagates uncaught, exactly as any other uncontained exception
     * would.
     *
     * @param array<array-key, mixed> $data
     * @throws \Kinetis\Cache\Exception\CacheArtifactExceptionInterface
     */
    public static function fromArray(array $data): static;
}
