<?php

declare(strict_types=1);

namespace Kinetis\Cache;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\PackageBootstrapInterface;

/**
 * Loads the bootstrap chain: every installed package's declared
 * {@see PackageBootstrapInterface} (see {@see PackageDiscovery}), then
 * the consumer's own bootstrap.php — in that order, so `instance()`'s
 * last-write-wins lets the application override any package binding.
 *
 * Registrations must run *before* boot() locks the binding set, so this
 * returns callable(AppScope, Config): void rather than acting on an
 * already-booted one. Config is a parameter because
 * {@see PackageBootstrapInterface::register()} takes one — a package
 * bootstrap reads configuration without resolving anything. Every caller
 * builds that Config (Config::fromEnvironment(), after
 * EnvFile::safeLoad()) and binds it on $app before running this callable,
 * so the instance a bootstrap receives is the one the container holds;
 * AppScope::boot()'s own default only covers a scope booted without one.
 *
 * $packageBootstraps carries the pre-resolved class list out of the AOT
 * cache in production; null (the default) discovers it live — the same
 * null-means-live convention the discoverers' own $paths parameter uses.
 *
 * HTTP routes are discovered by namespace instead — see
 * Kinetis\Http\Routing\RouteDiscovery, mirroring
 * Kinetis\Mcp\McpDiscovery/Kinetis\Console\CommandDiscovery — so there is
 * no equivalent loadRoutes() here.
 */
final class RoutesFile
{
    /**
     * @param list<class-string>|null $packageBootstraps
     * @return callable(AppScope, Config): void
     */
    public static function loadBootstrap(string $projectRoot, ?array $packageBootstraps = null): callable
    {
        $bootstrapClasses = $packageBootstraps ?? PackageDiscovery::bootstrapClasses($projectRoot);

        $path = $projectRoot . '/bootstrap.php';
        $appBootstrap = is_file($path)
            ? require $path
            : static function (): void {
                // No bootstrap.php at the project root — nothing to register.
            };

        /** @var callable(AppScope, Config): void $appBootstrap */
        return static function (AppScope $app, Config $config) use ($bootstrapClasses, $appBootstrap): void {
            foreach ($bootstrapClasses as $class) {
                // A stale production cache can name a bootstrap whose
                // package has since been removed — skipped with a
                // warning, the same tolerance PackageDiscovery gives a
                // declared-but-missing class on the live path, rather
                // than a fatal that takes the application down until
                // someone rebuilds the cache.
                if (!class_exists($class)) {
                    error_log("Package bootstrap {$class} is named by the compiled cache but no longer exists — was its package removed without rebuilding the cache (kinetis build)? Skipped.");

                    continue;
                }

                $bootstrap = new $class();

                if ($bootstrap instanceof PackageBootstrapInterface) {
                    $bootstrap->register($app, $config);
                }
            }

            $appBootstrap($app, $config);
        };
    }
}
