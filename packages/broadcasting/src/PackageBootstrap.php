<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting;

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
 * {@see BroadcastChannelRegistry} is not bound here at all — it's
 * declared as this package's own `extra.kinetis` `discovery` class
 * instead, so the framework itself compiles, caches, and binds it before
 * this method ever runs (see `Kinetis\Cache\PluginDiscovery`).
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
    }
}
