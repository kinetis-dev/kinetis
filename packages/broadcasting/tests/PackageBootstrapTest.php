<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests;

use Kinetis\Broadcasting\BroadcasterInterface;
use Kinetis\Broadcasting\BroadcastChannelRegistry;
use Kinetis\Broadcasting\Driver\PusherBroadcaster;
use Kinetis\Broadcasting\Exception\BroadcastingException;
use Kinetis\Broadcasting\NullBroadcaster;
use Kinetis\Broadcasting\PackageBootstrap;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use PHPUnit\Framework\TestCase;

final class PackageBootstrapTest extends TestCase
{
    public function test_with_no_driver_configured_binds_the_null_broadcaster(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config([]));
        $app->boot();

        self::assertInstanceOf(NullBroadcaster::class, $app->get(BroadcasterInterface::class));
    }

    public function test_the_pusher_driver_binds_a_configured_pusher_broadcaster(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config([
            'BROADCAST_DRIVER' => 'pusher',
            'BROADCAST_APP_ID' => '12345',
            'BROADCAST_KEY' => 'key',
            'BROADCAST_SECRET' => 'secret',
        ]));
        $app->boot();

        self::assertInstanceOf(PusherBroadcaster::class, $app->get(BroadcasterInterface::class));
    }

    public function test_the_pusher_driver_with_missing_config_throws_at_registration(): void
    {
        $this->expectException(\Kinetis\Config\Exception\MissingConfigException::class);

        new PackageBootstrap()->register(new AppScope(), new Config(['BROADCAST_DRIVER' => 'pusher']));
    }

    public function test_an_unknown_driver_throws_at_registration_naming_the_valid_set(): void
    {
        $this->expectException(BroadcastingException::class);
        $this->expectExceptionMessage('null", "pusher');

        new PackageBootstrap()->register(new AppScope(), new Config(['BROADCAST_DRIVER' => 'memcached']));
    }

    public function test_the_channel_registry_is_bound_and_resolvable(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config([]));
        $app->boot();

        self::assertInstanceOf(BroadcastChannelRegistry::class, $app->get(BroadcastChannelRegistry::class));
    }
}
