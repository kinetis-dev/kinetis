<?php

declare(strict_types=1);

namespace Kinetis\Cache;

use Kinetis\Cache\Exception\CacheArtifactExceptionInterface;
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
 * live-discovered, or reconstructed exactly once from a compiled cache
 * or a fresh compile by `resolveHttp()`/`resolveCli()` below — since
 * which of those an entry point uses depends on `AppEnvironment`/cache-
 * presence logic specific to that entry point, not something this
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
     * The whole "use the cache, or compile fresh" decision for an HTTP
     * boot, in one place. `loadHttpFromCache()` first; on a miss, calls
     * `$compile` exactly once and reconstructs every runtime object from
     * that same in-memory `CompiledCache` — `CacheStore::writeAll()`
     * only runs *after* every one of those reconstructions has already
     * succeeded, never before. A generation that fails to reconstruct
     * is therefore never published at all: nothing is written to disk
     * for a later process to find, misclassify as corrupt, and recompile
     * into the identical failure again. Reconstructing straight from
     * `$compiled` also means never reading back through `$store`, which
     * stays pinned to whatever generation (or absence of one) its own
     * first `load*()` call already resolved, possibly not even the one
     * `writeAll()` is about to publish if another process's compile
     * lands first. A failure while reconstructing from this fresh
     * compile is never caught here — it propagates, the same as any
     * other genuine bug — since there is no cache artifact left to blame
     * it on; `$compile` itself failing propagates for the identical
     * reason.
     *
     * `$compile` is injectable specifically so this whole decision is
     * testable without a real project root or filesystem-discovery
     * pass: a test can count invocations, or make it throw, and observe
     * the result directly.
     *
     * @param callable(): CompiledCache $compile
     * @return array{httpCache: HttpCache, router: Router, listenerRegistry: EventListenerRegistry, pluginInstances: array<class-string, object>}
     */
    public static function resolveHttp(CacheStore $store, callable $compile): array
    {
        $bundle = self::loadHttpFromCache($store);

        if ($bundle !== null) {
            return $bundle;
        }

        $compiled = $compile();
        $router = Router::fromArray($compiled->http->routes);
        $listenerRegistry = EventListenerRegistry::fromArray($compiled->events->listeners);
        $pluginInstances = PluginDiscovery::reconstruct($compiled->plugins->data);

        $store->writeAll($compiled);

        return [
            'httpCache' => $compiled->http,
            'router' => $router,
            'listenerRegistry' => $listenerRegistry,
            'pluginInstances' => $pluginInstances,
        ];
    }

    /**
     * The CLI's own equivalent of `resolveHttp()` — commands.php/
     * `CommandRegistry` in place of http.php/`Router`, otherwise
     * identical, including the same single-compile, reconstruct-before-
     * publish, no-reread-through-a-stale-pin, no-catch-around-a-fresh-
     * compile contract.
     *
     * @param callable(): CompiledCache $compile
     * @return array{commandCache: CommandCache, registry: CommandRegistry, listenerRegistry: EventListenerRegistry, pluginInstances: array<class-string, object>}
     */
    public static function resolveCli(CacheStore $store, callable $compile): array
    {
        $bundle = self::loadCliFromCache($store);

        if ($bundle !== null) {
            return $bundle;
        }

        $compiled = $compile();
        $registry = CommandRegistry::fromArray($compiled->commands->commands);
        $listenerRegistry = EventListenerRegistry::fromArray($compiled->events->listeners);
        $pluginInstances = PluginDiscovery::reconstruct($compiled->plugins->data);

        $store->writeAll($compiled);

        return [
            'commandCache' => $compiled->commands,
            'registry' => $registry,
            'listenerRegistry' => $listenerRegistry,
            'pluginInstances' => $pluginInstances,
        ];
    }

    /**
     * The entire "is there a usable cache generation for this HTTP
     * boot" decision, in one place: http.php, events.php, and
     * plugins.php from the same pinned generation
     * ({@see CacheStore}'s own docblock), each reconstructed into the
     * live runtime objects a boot actually needs — `Router` and
     * `EventListenerRegistry` included, not just the raw DTOs — or null
     * the instant any one of the three is absent, a stale format, or
     * fails to reconstruct for any reason, structural or otherwise,
     * including a route/command entry's own malformed shape or a
     * plugin's own rejection of its cached data. Never a hybrid of some
     * cached, some live-empty sections, and never a bundle accepted
     * here that then fails immediately outside this method once the
     * caller starts using it — every reconstruction this bundle
     * promises to have already done (`Router::fromArray()`,
     * `EventListenerRegistry::fromArray()`, `PluginDiscovery::
     * reconstruct()`) happens inside the same guarded call, not
     * deferred to the caller.
     *
     * The `catch` below is scoped narrowly two ways: by what code sits
     * inside the `try` (every `CacheStore::load*()` call and every
     * reconstruction that follows — working purely from data this same
     * call just read off disk — nothing else, no live discovery, no
     * `bootstrap.php` registration), and by exception type
     * (`CacheArtifactExceptionInterface` only). A plugin's own
     * `fromArray()` throwing anything else — a genuine defect, not a
     * data-shape rejection — propagates uncaught rather than being
     * silently relabelled "corrupt cache" and retried as a fresh
     * compile; see `CacheableDiscoveryInterface::fromArray()`'s own
     * contract.
     *
     * @return array{httpCache: HttpCache, router: Router, listenerRegistry: EventListenerRegistry, pluginInstances: array<class-string, object>}|null
     */
    public static function loadHttpFromCache(CacheStore $store): ?array
    {
        try {
            $http = $store->loadHttp();

            if ($http === null) {
                return null;
            }

            $events = $store->loadEvents();
            $plugins = $store->loadPlugins();

            if ($events === null || $plugins === null) {
                return null;
            }

            $router = Router::fromArray($http->routes);
            $listenerRegistry = EventListenerRegistry::fromArray($events->listeners);
            $pluginInstances = PluginDiscovery::reconstruct($plugins->data);
        } catch (CacheArtifactExceptionInterface) {
            return null;
        }

        return [
            'httpCache' => $http,
            'router' => $router,
            'listenerRegistry' => $listenerRegistry,
            'pluginInstances' => $pluginInstances,
        ];
    }

    /**
     * The CLI's own equivalent of `loadHttpFromCache()` — commands.php/
     * `CommandRegistry` in place of http.php/`Router`, otherwise
     * identical, including the same "never reads http.php" laziness and
     * the same narrow classification.
     *
     * @return array{commandCache: CommandCache, registry: CommandRegistry, listenerRegistry: EventListenerRegistry, pluginInstances: array<class-string, object>}|null
     */
    public static function loadCliFromCache(CacheStore $store): ?array
    {
        try {
            $commands = $store->loadCommands();

            if ($commands === null) {
                return null;
            }

            $events = $store->loadEvents();
            $plugins = $store->loadPlugins();

            if ($events === null || $plugins === null) {
                return null;
            }

            $registry = CommandRegistry::fromArray($commands->commands);
            $listenerRegistry = EventListenerRegistry::fromArray($events->listeners);
            $pluginInstances = PluginDiscovery::reconstruct($plugins->data);
        } catch (CacheArtifactExceptionInterface) {
            return null;
        }

        return [
            'commandCache' => $commands,
            'registry' => $registry,
            'listenerRegistry' => $listenerRegistry,
            'pluginInstances' => $pluginInstances,
        ];
    }
}
