<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting;

use Composer\InstalledVersions;
use Kinetis\Broadcasting\Driver\PusherBroadcaster;
use Kinetis\Broadcasting\Exception\BroadcastingException;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\PackageBootstrapInterface;
use Kinetis\RevoltHttpClient\Http;

/**
 * Declared via `extra.kinetis` and run by the framework ahead of the
 * application's own `bootstrap.php`. `BROADCAST_DRIVER` (default
 * `"null"`) selects {@see BroadcasterInterface}'s bound implementation —
 * `"null"` needs no other configuration; `"pusher"` additionally requires
 * `BROADCAST_APP_ID`/`BROADCAST_KEY`/`BROADCAST_SECRET`. The application's
 * `bootstrap.php` runs after this and wins on either binding, the same
 * override shape every other `extra.kinetis` package already gives.
 *
 * {@see BroadcastChannelRegistry} is discovered live here rather than
 * carried through the AOT cache — deliberately out of scope for this
 * pass: channel-authorizer discovery is a small, single-purpose scan
 * (mirroring `EventListenerRegistry`'s own registration cost, not
 * `Router`'s), and folding it into `Kinetis\Cache\Compiler`'s output
 * would touch a shared artifact every consumer's `bin/kinetis build`
 * produces, for a package most of them don't install.
 */
final class PackageBootstrap implements PackageBootstrapInterface
{
    #[\Override]
    public function register(AppScope $app, Config $config): void
    {
        // Built eagerly, unlike kinetis/session's own lazy driver
        // bindings — those defer to first use because some of their
        // drivers need a binding a *sibling* package's own bootstrap
        // provides, not guaranteed to have run yet at this point. Nothing
        // here has that dependency (Http is self-contained), so
        // validating BROADCAST_DRIVER and, for "pusher", the required
        // BROADCAST_APP_ID/KEY/SECRET happens right now, at worker boot —
        // a misconfiguration fails loudly before the first request, not
        // silently on whichever one happens to broadcast first.
        $driver = $config->string('BROADCAST_DRIVER', 'null');

        $broadcaster = match ($driver) {
            'null' => new NullBroadcaster(),
            'pusher' => PusherBroadcaster::fromConfig($config, new Http()),
            default => throw BroadcastingException::unknownDriver($driver),
        };

        $app->instance(BroadcasterInterface::class, $broadcaster);

        // Discovery itself stays lazy: nothing needs the channel registry
        // except BroadcastAuthController, so a request that never hits
        // /broadcasting/auth never pays for the scan.
        $app->bind(
            BroadcastChannelRegistry::class,
            static fn (): BroadcastChannelRegistry => BroadcastChannelDiscovery::discover(self::projectRoot()),
        );
    }

    /**
     * The consumer project's own root — what discovery scans.
     * Kinetis\Runtime\ProjectRoot::detect() only works from a file one
     * level below the real project root (public/index.php, bin/kinetis's
     * real bin-proxy) — never true of this class, which runs from
     * wherever Composer happens to install this package (a symlinked
     * path repository during development, an arbitrary vendor/ depth
     * otherwise). Composer's own runtime API reports the root package's
     * install path directly, regardless of either — the same mechanism
     * kinetis/mcp's own PackageBootstrap already uses for its /mcp route,
     * mirrored here rather than reinventing it.
     */
    private static function projectRoot(): string
    {
        $root = InstalledVersions::getRootPackage()['install_path'];

        return rtrim($root, '/');
    }
}
