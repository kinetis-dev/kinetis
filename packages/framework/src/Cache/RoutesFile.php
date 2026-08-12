<?php

declare(strict_types=1);

namespace Kinetis\Cache;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;

/**
 * Loads a consumer's bootstrap.php: public/index.php is the only entry
 * point that was ever expected to be consumer-customized —
 * bin/kinetis and kinetis/queue's bin/queue both construct a plain
 * `new AppScope()` and boot() it with no hook at all for a consumer to
 * register something a discovered command or a queued job actually needs
 * (a database connection pool, anything else not autowirable from a bare
 * class-string). Registrations must run *before* boot() locks the binding
 * set, so this returns callable(AppScope, Config): void rather than
 * acting on an already-booted one. Config is passed in directly, not
 * resolved from $app, because AppScope::boot() is what registers the
 * default Config binding in the first place — it doesn't exist yet at the
 * point this callable runs. Every caller already has one on hand
 * (Config::fromEnvironment(), after EnvFile::safeLoad()) for exactly this
 * reason.
 *
 * HTTP routes are discovered by namespace instead — see
 * Kinetis\Http\Routing\RouteDiscovery, mirroring
 * Kinetis\Mcp\McpDiscovery/Kinetis\Console\CommandDiscovery — so there is
 * no equivalent loadRoutes() here.
 */
final class RoutesFile
{
    /**
     * @return callable(AppScope, Config): void
     */
    public static function loadBootstrap(string $projectRoot): callable
    {
        $path = $projectRoot . '/bootstrap.php';

        if (!is_file($path)) {
            return static function (AppScope $app, Config $config): void {};
        }

        /** @var callable(AppScope, Config): void */
        return require $path;
    }
}
