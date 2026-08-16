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
 * already-booted one. Config is passed in directly, not resolved from
 * $app, because AppScope::boot() is what registers the default Config
 * binding in the first place — it doesn't exist yet at the point this
 * callable runs. Every caller already has one on hand
 * (Config::fromEnvironment(), after EnvFile::safeLoad()) for exactly
 * this reason.
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
                $bootstrap = new $class();

                if ($bootstrap instanceof PackageBootstrapInterface) {
                    $bootstrap->register($app, $config);
                }
            }

            $appBootstrap($app, $config);
        };
    }
}
