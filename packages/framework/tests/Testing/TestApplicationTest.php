<?php

declare(strict_types=1);

namespace Kinetis\Tests\Testing;

use Kinetis\Container\AppScope;
use Kinetis\Testing\TestApplication;
use Kinetis\Tests\Cache\Fixtures\AcmePackage\AcmeCacheableDiscovery;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves PluginDiscovery::bind() is genuinely wired into
 * TestApplication::boot() — a real, skeleton-equivalent boot, not just
 * PluginDiscovery's own unit tests, which never go through boot() at all.
 */
final class TestApplicationTest extends TestCase
{
    private const string PLAIN_DISCOVERY_ROOT = __DIR__ . '/../Cache/Fixtures/PackageVendorPlainDiscovery';
    private const string OVERRIDING_BOOTSTRAP_ROOT = __DIR__ . '/../Cache/Fixtures/PackageVendor';

    public function test_a_plugin_discovered_registry_is_bound_after_a_real_boot(): void
    {
        $application = TestApplication::boot(self::PLAIN_DISCOVERY_ROOT);

        $instance = $application->get(AcmeCacheableDiscovery::class);

        self::assertInstanceOf(AcmeCacheableDiscovery::class, $instance);
        // This fixture root has no bootstrap.php at all, so nothing could
        // have touched the binding after discovery — the value proves
        // PluginDiscovery::bind() itself ran, not just that *something*
        // is bound under this id.
        self::assertSame('from-compile:' . self::PLAIN_DISCOVERY_ROOT, $instance->source);
    }

    /**
     * The fixture at OVERRIDING_BOOTSTRAP_ROOT's own bootstrap.php
     * re-registers AcmeCacheableDiscovery — this only proves anything if
     * PluginDiscovery::bind() ran *before* the bootstrap chain, so the
     * discovered instance was already there to be overwritten rather
     * than asserted again afterward. Before this fix, TestApplication
     * never called PluginDiscovery::bind() at all, so this fixture's own
     * override had nothing to win against.
     */
    public function test_the_applications_own_bootstrap_php_overrides_the_discovered_instance(): void
    {
        $application = TestApplication::boot(self::OVERRIDING_BOOTSTRAP_ROOT);

        $instance = $application->get(AcmeCacheableDiscovery::class);

        self::assertSame('from-app-bootstrap', $instance->source);
    }

    public function test_a_before_boot_override_wins_over_the_discovered_instance(): void
    {
        $override = new AcmeCacheableDiscovery('from-before-boot');

        $application = TestApplication::boot(
            self::PLAIN_DISCOVERY_ROOT,
            beforeBoot: static function (AppScope $app) use ($override): void {
                $app->instance(AcmeCacheableDiscovery::class, $override);
            },
        );

        self::assertSame($override, $application->get(AcmeCacheableDiscovery::class));
    }

    /**
     * A test that boots a fresh application per test has to be able to
     * close what that boot opened, or every app-scoped resource is
     * abandoned once per test.
     */
    public function test_dispose_disposes_the_booted_application_and_is_idempotent(): void
    {
        $calls = 0;
        $application = TestApplication::boot(
            self::PLAIN_DISCOVERY_ROOT,
            beforeBoot: static function (AppScope $app) use (&$calls): void {
                $app->onDispose(static function () use (&$calls): void {
                    ++$calls;
                });
            },
        );

        $application->dispose();
        $application->dispose();

        self::assertSame(1, $calls);
        self::assertTrue($application->app->isDisposed());
    }

    /**
     * The scope exists before the step that fails, so what an earlier
     * registration already opened is closed — and the failure the test
     * is shown is still the one that actually broke the boot.
     */
    public function test_a_boot_failure_disposes_the_scope_it_had_already_created(): void
    {
        $disposed = false;

        try {
            TestApplication::boot(
                self::PLAIN_DISCOVERY_ROOT,
                beforeBoot: static function (AppScope $app) use (&$disposed): void {
                    $app->onDispose(static function () use (&$disposed): void {
                        $disposed = true;
                    });

                    throw new RuntimeException('test double registration failed');
                },
            );
            self::fail('Expected the boot failure to propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('test double registration failed', $e->getMessage());
        }

        self::assertTrue($disposed);
    }
}
