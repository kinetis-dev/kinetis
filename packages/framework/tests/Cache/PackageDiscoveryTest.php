<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache;

use Kinetis\Cache\PackageDiscovery;
use Kinetis\Config\Config;
use Kinetis\Console\CommandDiscovery;
use Kinetis\Cache\RoutesFile;
use Kinetis\Container\AppScope;
use Kinetis\Tests\Cache\Fixtures\AcmePackage\AcmeFixtureBootstrap;
use Kinetis\Tests\Cache\Fixtures\AcmePackage\AcmeMarker;
use Kinetis\Tests\Cache\Fixtures\AcmePackage\Console\AcmeFixtureCommand;
use PHPUnit\Framework\TestCase;

final class PackageDiscoveryTest extends TestCase
{
    private const string FIXTURE_ROOT = __DIR__ . '/Fixtures/PackageVendor';

    public function test_scan_roots_resolve_a_declared_prefix_through_the_packages_own_psr4_map(): void
    {
        $roots = PackageDiscovery::scanRoots(self::FIXTURE_ROOT);

        self::assertCount(1, $roots);
        self::assertSame('Kinetis\Tests\Cache\Fixtures\AcmePackage\Console\\', $roots[0]['prefix']);
        self::assertDirectoryExists($roots[0]['directory']);
        self::assertFileExists($roots[0]['directory'] . '/AcmeFixtureCommand.php');
    }

    public function test_a_prefix_outside_the_packages_own_psr4_map_is_skipped(): void
    {
        // acme/bad-prefix declares "Totally\Unrelated\Namespace\" — no
        // root of its own matches, so it contributes nothing (and the
        // acme/plain package with no extra.kinetis is invisible).
        $prefixes = array_column(PackageDiscovery::scanRoots(self::FIXTURE_ROOT), 'prefix');

        self::assertSame(['Kinetis\Tests\Cache\Fixtures\AcmePackage\Console\\'], $prefixes);
    }

    public function test_bootstrap_classes_come_from_extra_kinetis(): void
    {
        self::assertSame(
            [AcmeFixtureBootstrap::class],
            PackageDiscovery::bootstrapClasses(self::FIXTURE_ROOT),
        );
    }

    public function test_a_root_without_installed_json_yields_nothing(): void
    {
        self::assertSame([], PackageDiscovery::scanRoots('/nonexistent-root'));
        self::assertSame([], PackageDiscovery::bootstrapClasses('/nonexistent-root'));
    }

    public function test_command_discovery_finds_a_package_provided_command(): void
    {
        $registry = CommandDiscovery::discover(self::FIXTURE_ROOT);

        $definition = $registry->findCommand('acme:ping');

        self::assertNotNull($definition);
        self::assertSame(AcmeFixtureCommand::class, $definition->controllerClass);
    }

    public function test_load_bootstrap_runs_package_bootstraps_before_the_apps_own(): void
    {
        $app = new AppScope();
        $config = new Config([]);

        RoutesFile::loadBootstrap(self::FIXTURE_ROOT)($app, $config);
        $app->boot();

        // The package's binding survives where the app didn't touch it...
        self::assertSame('from-package', self::sourceOf($app, 'acme.binding'));
        // ...and the app's bootstrap.php, running last, wins the shared one.
        self::assertSame('from-app', self::sourceOf($app, 'acme.override'));
    }

    public function test_load_bootstrap_accepts_a_precompiled_bootstrap_list(): void
    {
        $app = new AppScope();
        $config = new Config([]);

        // The production path: the class list comes out of the AOT cache
        // instead of a live installed.json read.
        RoutesFile::loadBootstrap(self::FIXTURE_ROOT, [AcmeFixtureBootstrap::class])($app, $config);
        $app->boot();

        self::assertSame('from-package', self::sourceOf($app, 'acme.binding'));
        self::assertSame('from-app', self::sourceOf($app, 'acme.override'));
    }

    private static function sourceOf(AppScope $app, string $id): string
    {
        $marker = $app->get($id);
        \assert($marker instanceof AcmeMarker);

        return $marker->source;
    }
}
