<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache;

use Kinetis\Cache\Exception\InvalidCacheArtifactException;
use Kinetis\Cache\PluginDiscovery;
use Kinetis\Container\AppScope;
use Kinetis\Tests\Cache\Fixtures\AcmePackage\AcmeCacheableDiscovery;
use Kinetis\Tests\Cache\Fixtures\StrictPlugin\StrictCacheableDiscovery;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

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

    /**
     * bind() itself is layered on top of this now — the same algorithm,
     * reused, not reimplemented — so this pins reconstruct()'s own
     * behavior independently of bind()'s AppScope wiring.
     */
    public function test_reconstruct_builds_a_live_instance_per_entry(): void
    {
        $instances = PluginDiscovery::reconstruct([
            AcmeCacheableDiscovery::class => ['source' => 'from-cache'],
            StrictCacheableDiscovery::class => ['value' => 'ok'],
        ]);

        self::assertInstanceOf(AcmeCacheableDiscovery::class, $instances[AcmeCacheableDiscovery::class]);
        self::assertSame('from-cache', $instances[AcmeCacheableDiscovery::class]->source);
        self::assertInstanceOf(StrictCacheableDiscovery::class, $instances[StrictCacheableDiscovery::class]);
        self::assertSame('ok', $instances[StrictCacheableDiscovery::class]->value);
    }

    /**
     * reconstruct() has no fallback of its own — whatever a class's own
     * fromArray() throws for malformed data propagates unchanged. A
     * cache-bundle load (BootSequence) is the caller that turns this
     * into "treat the generation as corrupt, compile fresh instead";
     * this method itself must never swallow it.
     */
    public function test_reconstruct_propagates_whatever_a_class_own_fromarray_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('StrictCacheableDiscovery: malformed cached data.');

        PluginDiscovery::reconstruct([
            StrictCacheableDiscovery::class => ['wrong-key' => 'nope'],
        ]);
    }

    /**
     * A key that doesn't name a real class (a numeric-looking string
     * PHP would silently coerce to int, or simply a class that doesn't
     * exist) is classified corruption, not a raw fatal from calling a
     * static method on a non-class string.
     */
    public function test_reconstruct_rejects_a_key_naming_a_nonexistent_class(): void
    {
        $this->expectException(InvalidCacheArtifactException::class);

        PluginDiscovery::reconstruct(['App\\DoesNotExist' => []]);
    }

    public function test_reconstruct_rejects_a_class_that_does_not_implement_the_interface(): void
    {
        $this->expectException(InvalidCacheArtifactException::class);

        PluginDiscovery::reconstruct([stdClass::class => []]);
    }

    public function test_reconstruct_rejects_a_non_array_entry(): void
    {
        $this->expectException(InvalidCacheArtifactException::class);

        PluginDiscovery::reconstruct([AcmeCacheableDiscovery::class => 'not-an-array']);
    }

    /**
     * bindInstances() binds already-live objects with zero
     * reconstruction — the seam BootSequence::run() uses so an instance
     * resolveHttp()/resolveCli() already built is bound once, not
     * rebuilt a second time.
     */
    public function test_bind_instances_binds_already_constructed_objects_with_no_reconstruction(): void
    {
        $app = new AppScope();
        $instance = new AcmeCacheableDiscovery('already-built');

        PluginDiscovery::bindInstances($app, [AcmeCacheableDiscovery::class => $instance]);
        $app->boot();

        self::assertSame($instance, $app->get(AcmeCacheableDiscovery::class));
    }
}
