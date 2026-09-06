<?php

declare(strict_types=1);

namespace Kinetis\Cache;

use Kinetis\Cache\Exception\CacheArtifactExceptionInterface;
use Kinetis\Cache\Exception\CacheWriteException;
use Kinetis\Config\Config;
use Kinetis\Console\CommandRegistry;
use Kinetis\Container\AppScope;
use Kinetis\Events\EventListenerRegistry;
use Kinetis\Http\Routing\Router;

/**
 * The one piece of assembly every framework-managed entry point
 * (`public/index.php`, `bin/kinetis`, {@see \Kinetis\Testing\TestApplication},
 * and the identical reference copies in `kinetis/skeleton`/`kinetis/pingpong`)
 * delegates to, rather than each repeating it inline — extracted
 * specifically so none of them can independently drift from the others
 * on this exact ordering again: `PluginDiscovery::bindInstances()` and
 * the discovered `EventListenerRegistry` must both be bound *before* the
 * package/application bootstrap chain runs, or `bootstrap.php`'s own
 * last-write-wins override (resolving and augmenting a discovered
 * instance, or replacing it outright) has nothing yet bound to act on
 * and is silently reasserted over afterward instead.
 *
 * Deliberately stops short of calling `$app->boot()` itself:
 * `TestApplication` needs one more step — its own `$beforeBoot` callback
 * — to run after the bootstrap chain and before the container locks, and
 * folding `boot()` in here would leave no seam for that. Every caller
 * calls `boot()` right after its own final pre-boot seam — immediately
 * for `public/index.php`/`bin/kinetis`, or after `$beforeBoot` for
 * `TestApplication` — this only owns the part that was actually getting
 * the order wrong.
 *
 * $listenerRegistry and $pluginInstances are already-decided values —
 * live-discovered, or reconstructed exactly once from the compiled
 * artifact or a fresh compile by `resolveHttp()`/`resolveCli()` below —
 * since which of those an entry point uses depends on `AppEnvironment`/
 * cache-presence logic specific to that entry point, not something this
 * shared step needs to know about. Both shapes are bound identically
 * either way. `null` for `$pluginInstances` means "discover and
 * reconstruct live, right here" — the one case with no earlier
 * reconstruction to reuse.
 *
 * `$runBootstrap` exists only for `bin/kinetis`'s `#[Command(bootstrap:
 * false)]` commands. `false` skips `RoutesFile::loadBootstrap()`
 * entirely — the whole package-then-application chain, every installed
 * package's `PackageBootstrapInterface::register()` as well as the
 * project's own `bootstrap.php`, not just the latter — since a command
 * operating only on the project's static shape must not require the
 * configuration any of those registrations might demand (a database
 * connection factory a package binds, say). `PluginDiscovery::
 * bindInstances()` and $listenerRegistry are unconditional regardless:
 * the discovered/cached registries always bind, `$runBootstrap` only
 * gates the bootstrap chain on top of them. Every other caller leaves
 * this at its default, `true`, running package bootstraps first and the
 * application's own `bootstrap.php` last.
 */
final class BootSequence
{
    /**
     * @param array<class-string, object>|null $pluginInstances
     * @param list<class-string>|null $packageBootstraps
     */
    public static function run(
        AppScope $app,
        string $projectRoot,
        Config $config,
        EventListenerRegistry $listenerRegistry,
        ?array $pluginInstances,
        ?array $packageBootstraps,
        bool $runBootstrap = true,
    ): void {
        PluginDiscovery::bindInstances(
            $app,
            $pluginInstances ?? PluginDiscovery::reconstruct(PluginDiscovery::discover($projectRoot)),
        );
        $app->instance(EventListenerRegistry::class, $listenerRegistry);

        if ($runBootstrap) {
            RoutesFile::loadBootstrap($projectRoot, $packageBootstraps)($app, $config);
        }
    }

    /**
     * The whole "use the published artifact, or compile fresh" decision
     * for an HTTP boot, in one place.
     *
     * The artifact is read and reconstructed first. A missing,
     * format-incompatible, corrupt or unreconstructable one is not an
     * error: `$compile` runs exactly once, its result is reconstructed,
     * and only then is the same artifact `kinetis build` would have
     * produced published. Reconstructing before publishing is what stops
     * a compile that cannot be turned into live objects from being
     * written for the next process to find, misclassify as corrupt and
     * recompile into the identical failure.
     *
     * A failure while reconstructing from that fresh compile propagates,
     * as does `$compile` itself failing: there is no artifact left to
     * blame either on. A failure to *publish* does not — see
     * {@see publish()}.
     *
     * `$compile` is injectable specifically so this whole decision is
     * testable without a real project root or filesystem-discovery pass:
     * a test can count invocations, or make it throw, and observe the
     * result directly.
     *
     * @param callable(): CompiledCache $compile
     * @return array{httpCache: HttpCache, router: Router, listenerRegistry: EventListenerRegistry, pluginInstances: array<class-string, object>, packageBootstraps: list<class-string>}
     */
    public static function resolveHttp(CacheStore $store, callable $compile): array
    {
        $bundle = self::loadHttpFromCache($store);

        if ($bundle !== null) {
            return $bundle;
        }

        $compiled = $compile();
        $bundle = self::httpBundle($compiled);

        self::publish($store, $compiled);

        return $bundle;
    }

    /**
     * The CLI's own equivalent of `resolveHttp()` — a `CommandRegistry`
     * out of the `commands` section in place of a `Router` out of
     * `http`, otherwise identical, including the same single compile,
     * the same reconstruct-before-publish order and the same treatment
     * of a publish that fails.
     *
     * @param callable(): CompiledCache $compile
     * @return array{registry: CommandRegistry, listenerRegistry: EventListenerRegistry, pluginInstances: array<class-string, object>, packageBootstraps: list<class-string>}
     */
    public static function resolveCli(CacheStore $store, callable $compile): array
    {
        $bundle = self::loadCliFromCache($store);

        if ($bundle !== null) {
            return $bundle;
        }

        $compiled = $compile();
        $bundle = self::cliBundle($compiled);

        self::publish($store, $compiled);

        return $bundle;
    }

    /**
     * The entire "is there a usable artifact for this HTTP boot"
     * decision: the published file read whole and reconstructed into the
     * live objects a boot needs — `Router` and `EventListenerRegistry`
     * included, not just the raw sections — or null the instant it is
     * absent, a stale format, or fails to reconstruct for any reason,
     * structural or otherwise, including a route entry's own malformed
     * shape or a plugin's rejection of its cached data. Never a hybrid
     * of cached and live-empty sections, and never a bundle accepted
     * here that then fails outside this method once the caller starts
     * using it.
     *
     * The `catch` is scoped narrowly two ways: by what sits inside the
     * `try` (the read and the reconstructions that follow, working
     * purely from data this call just took off disk — no live discovery,
     * no `bootstrap.php` registration), and by exception type
     * (`CacheArtifactExceptionInterface` only). A plugin's own
     * `fromArray()` throwing anything else — a defect, not a data-shape
     * rejection — propagates rather than being relabelled "corrupt
     * cache" and retried as a fresh compile; see
     * `CacheableDiscoveryInterface::fromArray()`'s own contract.
     *
     * @return array{httpCache: HttpCache, router: Router, listenerRegistry: EventListenerRegistry, pluginInstances: array<class-string, object>, packageBootstraps: list<class-string>}|null
     */
    public static function loadHttpFromCache(CacheStore $store): ?array
    {
        try {
            $compiled = $store->load();

            return $compiled === null ? null : self::httpBundle($compiled);
        } catch (CacheArtifactExceptionInterface) {
            return null;
        }
    }

    /**
     * The CLI's own equivalent of `loadHttpFromCache()` — a
     * `CommandRegistry` out of the `commands` section in place of a
     * `Router` out of `http`, otherwise identical, including the same
     * narrow classification.
     *
     * @return array{registry: CommandRegistry, listenerRegistry: EventListenerRegistry, pluginInstances: array<class-string, object>, packageBootstraps: list<class-string>}|null
     */
    public static function loadCliFromCache(CacheStore $store): ?array
    {
        try {
            $compiled = $store->load();

            return $compiled === null ? null : self::cliBundle($compiled);
        } catch (CacheArtifactExceptionInterface) {
            return null;
        }
    }

    /**
     * @return array{httpCache: HttpCache, router: Router, listenerRegistry: EventListenerRegistry, pluginInstances: array<class-string, object>, packageBootstraps: list<class-string>}
     */
    private static function httpBundle(CompiledCache $compiled): array
    {
        return [
            'httpCache' => $compiled->http,
            'router' => Router::fromArray($compiled->http->routes),
            'listenerRegistry' => EventListenerRegistry::fromArray($compiled->events->listeners),
            'pluginInstances' => PluginDiscovery::reconstruct($compiled->plugins->data),
            'packageBootstraps' => $compiled->packageBootstraps,
        ];
    }

    /**
     * @return array{registry: CommandRegistry, listenerRegistry: EventListenerRegistry, pluginInstances: array<class-string, object>, packageBootstraps: list<class-string>}
     */
    private static function cliBundle(CompiledCache $compiled): array
    {
        return [
            'registry' => CommandRegistry::fromArray($compiled->commands->commands),
            'listenerRegistry' => EventListenerRegistry::fromArray($compiled->events->listeners),
            'pluginInstances' => PluginDiscovery::reconstruct($compiled->plugins->data),
            'packageBootstraps' => $compiled->packageBootstraps,
        ];
    }

    /**
     * Publishes the fallback compile, and keeps serving if it cannot.
     *
     * A machine that will not take the file — a read-only mount, a full
     * disk, a directory this process cannot create — has not made the
     * compiled value in memory any less correct, and this process
     * already holds it. So the request is served from that value and the
     * failure is reported once, on the boot that hit it, rather than
     * turned into an outage. Every later boot on that machine pays the
     * compile again and reports again, which is what makes a permanently
     * unwritable cache directory visible instead of silent.
     *
     * Only a persistence failure is contained. An
     * `UnexportableArtifactException` is a defect in what was compiled —
     * a live object in a plan — and it propagates, since the value this
     * boot would otherwise carry on with is the wrong one.
     */
    private static function publish(CacheStore $store, CompiledCache $compiled): void
    {
        try {
            $store->write($compiled);
        } catch (CacheWriteException $e) {
            error_log(
                'Kinetis compiled its AOT cache in memory but could not publish ' . $store->path()
                . ', so this boot paid for the compile and the next one will too: ' . $e->getMessage(),
            );
        }
    }
}
