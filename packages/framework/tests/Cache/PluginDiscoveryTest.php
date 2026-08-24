<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache;

use Kinetis\Cache\PluginDiscovery;
use Kinetis\Container\AppScope;
use Kinetis\Tests\Cache\Fixtures\AcmePackage\AcmeCacheableDiscovery;
use PHPUnit\Framework\TestCase;

final class PluginDiscoveryTest extends TestCase
{
    private const string FIXTURE_ROOT = __DIR__ . '/Fixtures/PackageVendor';

    public function test_discover_calls_compile_on_every_declared_discovery_class(): void
    {
        $data = PluginDiscovery::discover(self::FIXTURE_ROOT);

        self::assertSame(
            ['source' => 'from-compile:' . self::FIXTURE_ROOT],
            $data[AcmeCacheableDiscovery::class],
        );
    }

    public function test_bind_reconstructs_and_binds_each_entry_from_precomputed_data(): void
    {
        $app = new AppScope();

        PluginDiscovery::bind($app, self::FIXTURE_ROOT, [
            AcmeCacheableDiscovery::class => ['source' => 'from-cache'],
        ]);
        $app->boot();

        $instance = $app->get(AcmeCacheableDiscovery::class);
        self::assertInstanceOf(AcmeCacheableDiscovery::class, $instance);
        self::assertSame('from-cache', $instance->source);
    }

    public function test_bind_discovers_live_when_data_is_null(): void
    {
        $app = new AppScope();

        PluginDiscovery::bind($app, self::FIXTURE_ROOT, null);
        $app->boot();

        $instance = $app->get(AcmeCacheableDiscovery::class);
        self::assertInstanceOf(AcmeCacheableDiscovery::class, $instance);
        self::assertSame('from-compile:' . self::FIXTURE_ROOT, $instance->source);
    }
}
