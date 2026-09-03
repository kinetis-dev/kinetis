<?php

declare(strict_types=1);

namespace Kinetis\Tests\Events;

use Kinetis\Cache\BootSequence;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Events\EventDispatcher;
use Kinetis\Events\EventListenerDiscovery;
use Kinetis\Events\EventListenerRegistry;
use Kinetis\Testing\TestApplication;
use Kinetis\Tests\Events\Fixtures\EventListenerVendor\BeforeBootListener;
use Kinetis\Tests\Events\Fixtures\EventListenerVendor\BootstrapOrderConfirmed;
use Kinetis\Tests\Events\Fixtures\EventListenerVendor\PackageBootstrap;
use Kinetis\Tests\Events\Fixtures\Recorder;
use PHPUnit\Framework\TestCase;

/**
 * The discovered EventListenerRegistry must be bound before the
 * package/application bootstrap chain runs, the same precedence
 * PluginDiscovery::bind() already gets right — otherwise a bootstrap.php
 * override (or a $beforeBoot test double) is silently clobbered by a
 * later, unconditional rebind. `Kinetis\Cache\BootSequence` is the one
 * place this ordering now lives, shared by every framework-managed entry
 * point (public/index.php, bin/kinetis, TestApplication, and the
 * reference copies in kinetis/skeleton/kinetis/pingpong) — every test
 * here either calls it directly or goes through TestApplication, which
 * itself delegates to it, never a hand-reimplementation of the sequence
 * that could silently diverge from what those entry points actually run.
 *
 * Every case dispatches a real event through a real EventDispatcher and
 * reads a real Recorder, rather than settling for
 * EventListenerRegistry::listenersFor() or isRegistered() — a listener
 * list can be "correct" on paper while EventDispatcher still resolves it
 * from a disconnected scope, so only an actual dispatch proves the
 * wiring end-to-end.
 */
final class EventListenerRegistryBootstrapOrderTest extends TestCase
{
    private const string DISCOVERED_ROOT = __DIR__ . '/Fixtures/EventListenerVendor/Discovered';
    private const string AUGMENTING_ROOT = __DIR__ . '/Fixtures/EventListenerVendor/Augmenting';
    private const string OVERRIDING_ROOT = __DIR__ . '/Fixtures/EventListenerVendor/Overriding';

    /**
     * @return list<string>
     */
    private function dispatchAndRecord(AppScope $app): array
    {
        $scope = $app->createRequestScope();

        try {
            $scope->get(EventDispatcher::class)->dispatch(new BootstrapOrderConfirmed());

            return $scope->get(Recorder::class)->messages;
        } finally {
            $scope->dispose();
        }
    }

    // --- HTTP / live discovery, via TestApplication — which itself
    // delegates to BootSequence::run(), so these exercise the real shared
    // seam, not a copy of its logic. ---

    public function test_a_discovered_listener_is_reachable_after_a_real_boot(): void
    {
        // TEMPORARY CI DIAGNOSTIC — remove before merging.
        fwrite(STDERR, "\n[DIAG] DISCOVERED_ROOT=" . self::DISCOVERED_ROOT . "\n");
        fwrite(STDERR, '[DIAG] is_dir=' . var_export(is_dir(self::DISCOVERED_ROOT), true) . "\n");
        fwrite(STDERR, '[DIAG] composer.json exists=' . var_export(is_file(self::DISCOVERED_ROOT . '/composer.json'), true) . "\n");
        fwrite(STDERR, '[DIAG] composer.json content=' . (string) @file_get_contents(self::DISCOVERED_ROOT . '/composer.json') . "\n");
        fwrite(STDERR, '[DIAG] src dir exists=' . var_export(is_dir(self::DISCOVERED_ROOT . '/src'), true) . "\n");
        fwrite(STDERR, '[DIAG] listener file exists=' . var_export(is_file(self::DISCOVERED_ROOT . '/src/DiscoveredOrderListener.php'), true) . "\n");
        $rawRegistry = \Kinetis\Events\EventListenerDiscovery::discover(self::DISCOVERED_ROOT);
        fwrite(STDERR, '[DIAG] discover() toArray()=' . var_export($rawRegistry->toArray(), true) . "\n");
        fwrite(STDERR, '[DIAG] class_exists(DiscoveredOrderListener)=' . var_export(class_exists('Kinetis\Tests\Events\Fixtures\EventListenerVendor\Discovered\Src\DiscoveredOrderListener'), true) . "\n");

        $application = TestApplication::boot(self::DISCOVERED_ROOT);

        $result = $this->dispatchAndRecord($application->app);
        fwrite(STDERR, '[DIAG] dispatch result=' . var_export($result, true) . "\n");

        self::assertSame(['discovered'], $result);
    }

    /**
     * Augmenting/bootstrap.php resolves the already-bound
     * EventListenerRegistry and registers one more listener on it — this
     * only proves anything if the discovered registry was already bound
     * when that bootstrap.php ran, so both the discovered and the
     * augmented listener fire.
     */
    public function test_the_applications_own_bootstrap_php_can_augment_the_discovered_registry(): void
    {
        $application = TestApplication::boot(self::AUGMENTING_ROOT);

        $messages = $this->dispatchAndRecord($application->app);
        sort($messages);

        self::assertSame(['augmented', 'discovered'], $messages);
    }

    /**
     * Overriding/bootstrap.php replaces EventListenerRegistry outright
     * with a fresh instance holding only ReplacementListener — this only
     * proves anything if the discovered registry was already bound when
     * that bootstrap.php ran, so it had something real to overwrite
     * rather than being silently reasserted after.
     */
    public function test_the_applications_own_bootstrap_php_can_replace_the_discovered_registry_outright(): void
    {
        $application = TestApplication::boot(self::OVERRIDING_ROOT);

        self::assertSame(['replaced'], $this->dispatchAndRecord($application->app));
    }

    /**
     * $beforeBoot must win over both discovery and a bootstrap.php
     * override — run against Overriding, whose own bootstrap.php already
     * replaces the registry once, so this proves $beforeBoot's own
     * replacement wins over that too, not merely over live discovery.
     */
    public function test_a_before_boot_override_wins_over_both_discovery_and_bootstrap_php(): void
    {
        $application = TestApplication::boot(
            self::OVERRIDING_ROOT,
            beforeBoot: static function (AppScope $app): void {
                $registry = new EventListenerRegistry();
                $registry->register(BeforeBootListener::class);

                $app->instance(EventListenerRegistry::class, $registry);
            },
        );

        self::assertSame(['before-boot'], $this->dispatchAndRecord($application->app));
    }

    // --- BootSequence::run() called directly — the shape public/index.php's
    // production branch and bin/kinetis both actually use: a
    // fromArray()-reconstructed registry (never live discovery), and (for
    // bin/kinetis specifically) $runBootstrap toggled per #[Command(bootstrap:
    // ...)]. TestApplication never exercises either of these, since it
    // always discovers live and always runs the bootstrap chain — these
    // four tests are what closes that gap, against the real seam, not a
    // reimplementation of it. ---

    private function app(): AppScope
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config([]));

        return $app;
    }

    /**
     * The HTTP production / bin/kinetis --cached shape: a compiled
     * cache's own array data, reconstructed via fromArray() exactly the
     * way CacheStore::loadEvents() feeds it, with the bootstrap chain
     * enabled — proving the identical augment precedence TestApplication
     * already proved for live discovery also holds for a
     * cache-reconstructed registry.
     */
    public function test_boot_sequence_augments_a_compiled_cache_reconstructed_registry_with_bootstrap_enabled(): void
    {
        $listenerRegistry = EventListenerRegistry::fromArray(
            EventListenerDiscovery::discover(self::AUGMENTING_ROOT)->toArray(),
        );

        $app = $this->app();
        BootSequence::run($app, self::AUGMENTING_ROOT, $app->get(Config::class), $listenerRegistry, null, null);
        $app->boot();

        $messages = $this->dispatchAndRecord($app);
        sort($messages);

        self::assertSame(['augmented', 'discovered'], $messages);
    }

    /**
     * The CLI #[Command(bootstrap: true)] / live-discovery shape: the
     * same BootSequence::run() call bin/kinetis's development branch
     * makes, with a live-discovered registry and $runBootstrap left at
     * its default (true) — proving the augment precedence directly
     * against the seam CLI commands actually call, independent of
     * TestApplication's own HTTP-shaped wiring.
     */
    public function test_boot_sequence_augments_a_live_discovered_registry_with_bootstrap_enabled(): void
    {
        $listenerRegistry = EventListenerDiscovery::discover(self::AUGMENTING_ROOT);

        $app = $this->app();
        BootSequence::run($app, self::AUGMENTING_ROOT, $app->get(Config::class), $listenerRegistry, null, null);
        $app->boot();

        $messages = $this->dispatchAndRecord($app);
        sort($messages);

        self::assertSame(['augmented', 'discovered'], $messages);
    }

    /**
     * The CLI #[Command(bootstrap: false)] / live-discovery shape: the
     * discovered registry must still be bound and dispatchable — a
     * bootstrap:false command still resolves a discovered listener
     * normally — but the *entire* bootstrap chain must never run, not
     * just the application's own bootstrap.php. Run against Augmenting
     * with a real PackageBootstrap given as $packageBootstraps (rather
     * than null, which would give this nothing to skip): if bootstrap:
     * false only gated the application half of the chain, this package
     * bootstrap would still run and "package" would leak into the
     * result. Augmenting/bootstrap.php itself must never run either, so
     * its own "augmented" is absent too — only the discovered listener
     * fires.
     */
    public function test_boot_sequence_with_bootstrap_disabled_still_binds_a_live_discovered_registry_but_skips_the_whole_chain(): void
    {
        $listenerRegistry = EventListenerDiscovery::discover(self::AUGMENTING_ROOT);

        $app = $this->app();
        BootSequence::run(
            $app,
            self::AUGMENTING_ROOT,
            $app->get(Config::class),
            $listenerRegistry,
            null,
            [PackageBootstrap::class],
            runBootstrap: false,
        );
        $app->boot();

        self::assertSame(['discovered'], $this->dispatchAndRecord($app));
    }

    /**
     * The CLI #[Command(bootstrap: false)] / --cached shape: the same
     * guarantee as the live case above, but for a fromArray()-
     * reconstructed registry — the discovered/cached listener still
     * fires, and neither the given PackageBootstrap nor
     * Augmenting/bootstrap.php's own override ever runs, proving the
     * whole chain is skipped for a cache-reconstructed registry too, not
     * only a live-discovered one.
     */
    public function test_boot_sequence_with_bootstrap_disabled_still_binds_a_compiled_cache_reconstructed_registry_but_skips_the_whole_chain(): void
    {
        $listenerRegistry = EventListenerRegistry::fromArray(
            EventListenerDiscovery::discover(self::AUGMENTING_ROOT)->toArray(),
        );

        $app = $this->app();
        BootSequence::run(
            $app,
            self::AUGMENTING_ROOT,
            $app->get(Config::class),
            $listenerRegistry,
            null,
            [PackageBootstrap::class],
            runBootstrap: false,
        );
        $app->boot();

        self::assertSame(['discovered'], $this->dispatchAndRecord($app));
    }

    // --- $packageBootstraps — a real PackageBootstrapInterface class,
    // the way an installed package's own extra.kinetis.bootstrap
    // registration actually runs (RoutesFile::loadBootstrap()'s own
    // `new $class()`, package bootstraps first, then the project's own
    // bootstrap.php last). The two bootstrap-disabled cases just above
    // already used this same fixture to prove the package stage is
    // skipped, not just the application one, when the chain doesn't run
    // at all; every enabled-chain case before that only ever proved the
    // application-root bootstrap.php's own precedence. These two prove
    // the package stage specifically when the chain *does* run, and that
    // the application still wins last over it. ---

    /**
     * Discovered (no application-level bootstrap.php at all) plus one
     * package bootstrap that augments — proving a package bootstrap can
     * resolve and add to the discovered registry the same way an
     * application's own bootstrap.php can, and that this only works
     * because BootSequence::run() bound the registry before running it.
     */
    public function test_a_package_bootstrap_can_augment_the_discovered_registry(): void
    {
        $listenerRegistry = EventListenerDiscovery::discover(self::DISCOVERED_ROOT);

        $app = $this->app();
        BootSequence::run(
            $app,
            self::DISCOVERED_ROOT,
            $app->get(Config::class),
            $listenerRegistry,
            null,
            [PackageBootstrap::class],
        );
        $app->boot();

        $messages = $this->dispatchAndRecord($app);
        sort($messages);

        self::assertSame(['discovered', 'package'], $messages);
    }

    /**
     * Overriding — whose own bootstrap.php replaces the registry outright
     * with ReplacementListener — plus the same package bootstrap that
     * augments first. The package bootstrap runs first and adds its
     * listener to the discovered set; the application's own
     * bootstrap.php then runs last and replaces the registry entirely,
     * so only "replaced" survives — proving the application still wins
     * last, over a package bootstrap's own change, not merely over
     * discovery alone.
     */
    public function test_the_applications_own_bootstrap_php_wins_last_over_a_package_bootstraps_augmentation(): void
    {
        $listenerRegistry = EventListenerDiscovery::discover(self::OVERRIDING_ROOT);

        $app = $this->app();
        BootSequence::run(
            $app,
            self::OVERRIDING_ROOT,
            $app->get(Config::class),
            $listenerRegistry,
            null,
            [PackageBootstrap::class],
        );
        $app->boot();

        self::assertSame(['replaced'], $this->dispatchAndRecord($app));
    }
}
