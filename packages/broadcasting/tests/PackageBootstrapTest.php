<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests;

use Kinetis\Broadcasting\BroadcasterInterface;
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

    /**
     * The normal binding — `new Http()`, no injected client — is what
     * reads `BROADCAST_TIMEOUT`, so a deadline that cannot bound
     * anything stops the worker at boot instead of reaching a request.
     */
    public function test_a_non_positive_broadcast_timeout_fails_at_registration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('BROADCAST_TIMEOUT');

        new PackageBootstrap()->register(new AppScope(), new Config([
            'BROADCAST_DRIVER' => 'pusher',
            'BROADCAST_APP_ID' => '12345',
            'BROADCAST_KEY' => 'key',
            'BROADCAST_SECRET' => 'secret',
            'BROADCAST_TIMEOUT' => '0',
        ]));
    }

    public function test_an_unknown_driver_throws_at_registration_naming_the_valid_set(): void
    {
        $this->expectException(BroadcastingException::class);
        $this->expectExceptionMessage('null", "pusher');

        new PackageBootstrap()->register(new AppScope(), new Config(['BROADCAST_DRIVER' => 'memcached']));
    }
}
