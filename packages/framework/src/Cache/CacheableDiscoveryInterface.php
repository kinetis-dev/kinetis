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
 * finding the declaration, calling `compile()` to build the shared cache
 * file, loading it back, and binding the reconstructed instance into
 * `AppScope` — is the framework's own job (see {@see PluginDiscovery}
 * and {@see Compiler}). A package's own `PackageBootstrapInterface`
 * implementation never touches this data at all: by the time it runs,
 * the framework has already bound it.
 */
interface CacheableDiscoveryInterface
{
    /**
     * Runs live discovery and returns plain, `var_export()`-safe data —
     * no objects, no closures. Called by `Compiler` to build the shared
     * cache file, and by `PluginDiscovery::discover()` as the
     * development-mode/no-cache-yet equivalent.
     *
     * @return array<array-key, mixed>
     */
    public static function compile(string $projectRoot): array;

    /**
     * Reconstructs a live instance from `compile()`'s own output —
     * whether that data just came from a fresh `compile()` call or was
     * loaded back out of a `var_export()`'d cache file, the result must
     * be identical either way.
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): static;
}
