<?php

declare(strict_types=1);

namespace Kinetis\Tests\Console\Fixtures;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\PackageBootstrapInterface;
use Kinetis\Events\EventListenerRegistry;
use Kinetis\Runtime\ProjectRoot;
use Kinetis\Tests\Cache\Fixtures\Events\DiscoveredEvent;

/**
 * Resolves a plugin-discovered instance and the discovered
 * EventListenerRegistry from AppScope, and writes what it saw to MARKER
 * inside the project root, so a test can read it even when the AppScope
 * it ran on is internal to a command.
 */
final class DiscoveredStatePackageBootstrap implements PackageBootstrapInterface
{
    public const string MARKER = 'package-bootstrap-discovered.json';

    #[\Override]
    public function register(AppScope $app, Config $config): void
    {
        $root = $app->get(ProjectRoot::class)->path;
        $plugin = $app->get(PrivateConstructorDiscovery::class);
        $listeners = $app->get(EventListenerRegistry::class)->listenersFor(DiscoveredEvent::class);

        file_put_contents($root . '/' . self::MARKER, json_encode([
            'plugin' => $plugin->source,
            'listeners' => array_column($listeners, 'class'),
        ], JSON_THROW_ON_ERROR));
    }
}
