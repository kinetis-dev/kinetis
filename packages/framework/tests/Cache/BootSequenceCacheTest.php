<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache;

use Kinetis\Cache\BootSequence;
use Kinetis\Cache\CacheFormat;
use Kinetis\Cache\CacheStore;
use Kinetis\Cache\CommandCache;
use Kinetis\Cache\CompiledCache;
use Kinetis\Cache\EventCache;
use Kinetis\Cache\Exception\InvalidCacheArtifactException;
use Kinetis\Cache\Exception\UnexportableArtifactException;
use Kinetis\Cache\HttpCache;
use Kinetis\Cache\PluginCache;
use Kinetis\Console\CommandRegistry;
use Kinetis\Http\Routing\Router;
use Kinetis\Tests\Cache\Fixtures\StrictPlugin\BuggyCacheableDiscovery;
use Kinetis\Tests\Cache\Fixtures\StrictPlugin\CountingCacheableDiscovery;
use Kinetis\Tests\Cache\Fixtures\StrictPlugin\StrictCacheableDiscovery;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * BootSequence::loadHttpFromCache()/loadCliFromCache() (the cache-hit
 * half), resolveHttp()/resolveCli() (the full cache-or-compile
 * decision) and assertReconstructable() (the whole artifact, which is
 * what a build publishes through) against a real published artifact —
 * proving every runtime object a boot needs (Router/CommandRegistry
 * included, not just the raw DTOs) is reconstructed exactly once, that
 * a corrupt artifact is a clean miss rather than an uncaught fatal, and
 * that a genuine defect in a plugin's own reconstruction never gets
 * misclassified as cache corruption. Every test publishes through a
 * real CacheStore, then corrupts the real file on disk before reading
 * it back — not a mock of any fromArray() method.
 */
final class BootSequenceCacheTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/kinetis_boot_sequence_cache_test_' . bin2hex(random_bytes(8));
        CountingCacheableDiscovery::$constructions = 0;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $entry) {
            is_dir($entry) ? @rmdir($entry) : @unlink($entry);
        }

        @rmdir($this->directory);
    }

    private function validCompiledCache(): CompiledCache
    {
        $http = new HttpCache(
            routes: [['httpMethod' => 'GET', 'pathTemplate' => '/x', 'controllerClass' => 'App\\C', 'controllerMethod' => 'm', 'status' => 200, 'middleware' => []]],
            httpBindingPlans: [],
            hydrationPlans: [],
            globalMiddleware: [],
            openApiMiddleware: [],
        );
        $commands = new CommandCache([
            ['name' => 'app:x', 'description' => '', 'controllerClass' => 'App\\C', 'controllerMethod' => 'm', 'takesArguments' => false, 'bootstrap' => true],
        ]);
        $events = new EventCache([
            'App\\SomeEvent' => [['class' => 'App\\SomeListener', 'method' => 'handle', 'priority' => 50, 'queued' => false]],
        ]);
        $plugins = new PluginCache([StrictCacheableDiscovery::class => ['value' => 'ok']]);

        return new CompiledCache($http, $commands, $events, $plugins, ['App\\PackageBootstrap']);
    }

    private function publish(CompiledCache $cache): void
    {
        (new CacheStore($this->directory))->write($cache);
    }

    /**
     * Overwrites the published artifact with $data, so a test can
     * corrupt exactly one field of an otherwise well-formed file.
     *
     * @param array<array-key, mixed> $data
     */
    private function overwriteArtifact(array $data): void
    {
        file_put_contents(
            (new CacheStore($this->directory))->path(),
            "<?php\n\nreturn " . var_export($data, true) . ";\n",
        );
    }

    // --- loadHttpFromCache()/loadCliFromCache(): the cache-hit half ---

    public function test_no_artifact_at_all_is_a_miss_for_both_bundles(): void
    {
        $store = new CacheStore($this->directory);

        self::assertNull(BootSequence::loadHttpFromCache($store));
        self::assertNull(BootSequence::loadCliFromCache($store));
    }

    public function test_a_valid_artifact_yields_both_bundles_with_reconstructed_runtime_objects(): void
    {
        $this->publish($this->validCompiledCache());

        $http = BootSequence::loadHttpFromCache(new CacheStore($this->directory));
        self::assertNotNull($http);
        self::assertInstanceOf(Router::class, $http['router']);
        self::assertSame('App\\C', $http['router']->match('GET', '/x')->route->controllerClass);
        self::assertSame(['App\\SomeListener', 'handle'], [
            $http['listenerRegistry']->listenersFor('App\\SomeEvent')[0]['class'],
            $http['listenerRegistry']->listenersFor('App\\SomeEvent')[0]['method'],
        ]);
        self::assertInstanceOf(StrictCacheableDiscovery::class, $http['pluginInstances'][StrictCacheableDiscovery::class]);
        self::assertSame('ok', $http['pluginInstances'][StrictCacheableDiscovery::class]->value);
        self::assertSame(['App\\PackageBootstrap'], $http['packageBootstraps']);

        $cli = BootSequence::loadCliFromCache(new CacheStore($this->directory));
        self::assertNotNull($cli);
        self::assertInstanceOf(CommandRegistry::class, $cli['registry']);
        self::assertSame('App\\C', $cli['registry']->findCommand('app:x')?->controllerClass);
        self::assertSame(['App\\SomeListener', 'handle'], [
            $cli['listenerRegistry']->listenersFor('App\\SomeEvent')[0]['class'],
            $cli['listenerRegistry']->listenersFor('App\\SomeEvent')[0]['method'],
        ]);
        self::assertInstanceOf(StrictCacheableDiscovery::class, $cli['pluginInstances'][StrictCacheableDiscovery::class]);
        self::assertSame(['App\\PackageBootstrap'], $cli['packageBootstraps']);
    }

    /**
     * A format version this build does not speak makes the artifact a
     * miss for both bundles at once — there is one file, so no bundle
     * can ever be served from a shape another one rejected.
     */
    public function test_a_wrong_format_version_is_a_miss_for_both_bundles(): void
    {
        $this->publish($this->validCompiledCache());

        $data = $this->validCompiledCache()->toArray();
        $data['formatVersion'] = CacheFormat::VERSION + 1;
        $this->overwriteArtifact($data);

        self::assertNull(BootSequence::loadHttpFromCache(new CacheStore($this->directory)));
        self::assertNull(BootSequence::loadCliFromCache(new CacheStore($this->directory)));
    }

    /**
     * An artifact whose hydration plans were compiled before
     * HydrationPlanParameter gained its `objectMap` field — a real
     * `kinetis build` output from an earlier framework version, not an
     * invented corruption. Hydrator::validatePlans() exact-keys the
     * parameter shape, so the stale entry is rejected as a data-shape
     * problem and the whole artifact is a clean miss, exactly like any
     * other shape the running build does not speak. No compatibility
     * reader fills the missing field in.
     */
    public function test_a_hydration_plan_from_before_the_object_map_field_is_a_miss(): void
    {
        $data = $this->compiledCacheWithHydrationPlan()->toArray();
        unset($data['http']['hydrationPlans']['App\\Dto']['parameters'][0]['objectMap']);
        mkdir($this->directory, 0775, true);
        $this->overwriteArtifact($data);

        self::assertNull(BootSequence::loadHttpFromCache(new CacheStore($this->directory)));
    }

    /**
     * What a worker then does with that stale artifact: reject it,
     * compile once, and publish the current shape — the live recovery
     * contract, with no migration step in between.
     */
    public function test_a_stale_hydration_plan_makes_the_boot_compile_once_and_republish(): void
    {
        $data = $this->compiledCacheWithHydrationPlan()->toArray();
        unset($data['http']['hydrationPlans']['App\\Dto']['parameters'][0]['objectMap']);
        mkdir($this->directory, 0775, true);
        $this->overwriteArtifact($data);

        $fresh = $this->compiledCacheWithHydrationPlan();
        $calls = 0;
        BootSequence::resolveHttp(new CacheStore($this->directory), function () use (&$calls, $fresh): CompiledCache {
            $calls++;

            return $fresh;
        });

        self::assertSame(1, $calls, 'a stale artifact is compiled past exactly once, never repeatedly');

        $republished = BootSequence::loadHttpFromCache(new CacheStore($this->directory));
        self::assertNotNull($republished);
        self::assertFalse($republished['httpCache']->hydrationPlans['App\\Dto']['parameters'][0]['objectMap']);
    }

    /**
     * The valid artifact, carrying one hydration plan whose parameter is
     * written in the current shape.
     */
    private function compiledCacheWithHydrationPlan(): CompiledCache
    {
        $cache = $this->validCompiledCache();
        $http = new HttpCache(
            routes: $cache->http->routes,
            httpBindingPlans: [],
            hydrationPlans: [
                'App\\Dto' => [
                    'className' => 'App\\Dto',
                    'hasConstructor' => true,
                    'parameters' => [[
                        'name' => 'meta', 'scalarType' => 'array', 'dtoClass' => null, 'nestedPlan' => null,
                        'listItemClass' => null, 'listItemPlan' => null, 'objectMap' => false,
                        'hasDefault' => false, 'defaultValue' => null, 'allowsNull' => false, 'constraints' => [],
                    ]],
                ],
            ],
            globalMiddleware: [],
            openApiMiddleware: [],
        );

        return new CompiledCache($http, $cache->commands, $cache->events, $cache->plugins, $cache->packageBootstraps);
    }

    /**
     * A structurally malformed event registry — a duplicate {class,
     * method} pair, which EventListenerRegistry::fromArray() rejects by
     * throwing InvalidListenerException (see its own docblock). An
     * artifact carrying one must be classified as corrupt and turned
     * into a clean miss here, never an uncaught fatal at boot.
     */
    public function test_a_structurally_malformed_event_registry_is_a_miss_not_an_uncaught_exception(): void
    {
        $cache = $this->validCompiledCache();
        $corruptEvents = new EventCache([
            'App\\SomeEvent' => [
                ['class' => 'App\\SomeListener', 'method' => 'handle', 'priority' => 50, 'queued' => false],
                ['class' => 'App\\SomeListener', 'method' => 'handle', 'priority' => 10, 'queued' => false],
            ],
        ]);
        $this->publish(new CompiledCache($cache->http, $cache->commands, $corruptEvents, $cache->plugins));

        self::assertNull(BootSequence::loadHttpFromCache(new CacheStore($this->directory)));
        self::assertNull(BootSequence::loadCliFromCache(new CacheStore($this->directory)));
    }

    /**
     * The same classification, triggered from the plugin section
     * instead — a package's own fromArray() rejecting its own malformed
     * cached data (via an exception implementing
     * CacheArtifactExceptionInterface, per that interface's own
     * contract) is exactly the same class of "this artifact is corrupt"
     * signal as EventListenerRegistry's.
     */
    public function test_a_plugins_own_malformed_cached_data_is_a_miss_not_an_uncaught_exception(): void
    {
        $cache = $this->validCompiledCache();
        $corruptPlugins = new PluginCache([StrictCacheableDiscovery::class => ['wrong-key' => 'nope']]);
        $this->publish(new CompiledCache($cache->http, $cache->commands, $cache->events, $corruptPlugins));

        self::assertNull(BootSequence::loadHttpFromCache(new CacheStore($this->directory)));
    }

    /**
     * A plugin's own fromArray() throwing something that does NOT
     * implement CacheArtifactExceptionInterface — standing in for a
     * genuine defect (an undefined method, a dependency failure), never
     * a data-shape problem — must propagate uncaught, not be silently
     * relabelled "corrupt cache" and turned into a miss.
     */
    public function test_a_plugins_own_genuine_defect_propagates_uncaught_not_classified_as_a_miss(): void
    {
        $cache = $this->validCompiledCache();
        $buggyPlugins = new PluginCache([BuggyCacheableDiscovery::class => []]);
        $this->publish(new CompiledCache($cache->http, $cache->commands, $cache->events, $buggyPlugins));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('BuggyCacheableDiscovery: a genuine defect, not a data-shape problem.');

        BootSequence::loadHttpFromCache(new CacheStore($this->directory));
    }

    /**
     * A malformed top-level field on the HTTP section (right format
     * version, but a field missing or wrong-typed) must also be a miss —
     * not an uncaught TypeError escaping from inside
     * HttpCache::fromArray(), before loadHttpFromCache()'s own try block
     * would otherwise have started.
     */
    public function test_a_malformed_top_level_field_in_the_http_section_is_a_miss(): void
    {
        $data = $this->validCompiledCache()->toArray();
        unset($data['http']['globalMiddleware']);
        mkdir($this->directory, 0775, true);
        $this->overwriteArtifact($data);

        self::assertNull(BootSequence::loadHttpFromCache(new CacheStore($this->directory)));
    }

    public function test_a_malformed_top_level_field_in_the_commands_section_is_a_miss(): void
    {
        $data = $this->validCompiledCache()->toArray();
        unset($data['commands']['commands']);
        mkdir($this->directory, 0775, true);
        $this->overwriteArtifact($data);

        self::assertNull(BootSequence::loadCliFromCache(new CacheStore($this->directory)));
    }

    /**
     * The package-bootstrap list is validated once, at the artifact's
     * own top level, so a file missing it is a miss for both entry
     * points at once.
     */
    public function test_a_missing_artifact_level_package_bootstrap_list_is_a_miss_for_both_bundles(): void
    {
        $data = $this->validCompiledCache()->toArray();
        unset($data['packageBootstraps']);
        mkdir($this->directory, 0775, true);
        $this->overwriteArtifact($data);

        self::assertNull(BootSequence::loadHttpFromCache(new CacheStore($this->directory)));
        self::assertNull(BootSequence::loadCliFromCache(new CacheStore($this->directory)));
    }

    /**
     * A malformed *entry* within an otherwise well-shaped HTTP section —
     * a route missing its own "httpMethod" — must be a miss too, not an
     * uncaught TypeError from inside Router::fromArray().
     */
    public function test_a_malformed_route_entry_is_a_miss(): void
    {
        $data = $this->validCompiledCache()->toArray();
        unset($data['http']['routes'][0]['httpMethod']);
        mkdir($this->directory, 0775, true);
        $this->overwriteArtifact($data);

        self::assertNull(BootSequence::loadHttpFromCache(new CacheStore($this->directory)));
    }

    public function test_a_malformed_command_entry_is_a_miss(): void
    {
        $data = $this->validCompiledCache()->toArray();
        unset($data['commands']['commands'][0]['bootstrap']);
        mkdir($this->directory, 0775, true);
        $this->overwriteArtifact($data);

        self::assertNull(BootSequence::loadCliFromCache(new CacheStore($this->directory)));
    }

    /**
     * A syntax-corrupt artifact (a truncated write, disk corruption,
     * hand tampering — never a shape CacheStore::write() itself
     * produces) must be a clean miss: require()'s own ParseError is
     * exactly the same class of "unusable" signal as a missing file, not
     * an uncaught fatal escaping this method.
     */
    public function test_a_syntax_corrupt_artifact_is_a_miss_not_an_uncaught_parse_error(): void
    {
        $this->publish($this->validCompiledCache());
        file_put_contents((new CacheStore($this->directory))->path(), "<?php\n\nthis is not valid PHP at all {{{\n");

        self::assertNull(BootSequence::loadHttpFromCache(new CacheStore($this->directory)));
        self::assertNull(BootSequence::loadCliFromCache(new CacheStore($this->directory)));
    }

    /**
     * A rejected bundle is a pure decision, not a retry loop: the same
     * still-corrupt artifact, read twice in a row, returns null both
     * times — never a second attempt that behaves differently, and never
     * an exception escaping on either call.
     */
    public function test_a_rejected_bundle_returns_null_deterministically_not_a_retry_loop(): void
    {
        $cache = $this->validCompiledCache();
        $corruptPlugins = new PluginCache([StrictCacheableDiscovery::class => ['wrong-key' => 'nope']]);
        $this->publish(new CompiledCache($cache->http, $cache->commands, $cache->events, $corruptPlugins));

        $reader = new CacheStore($this->directory);
        self::assertNull(BootSequence::loadHttpFromCache($reader));
        self::assertNull(BootSequence::loadHttpFromCache($reader));
    }

    // --- resolveHttp()/resolveCli(): the full cache-or-compile decision ---

    public function test_resolve_http_uses_the_cache_and_never_invokes_compile_on_a_hit(): void
    {
        $this->publish($this->validCompiledCache());

        $calls = 0;
        $resolved = BootSequence::resolveHttp(new CacheStore($this->directory), function () use (&$calls): CompiledCache {
            $calls++;

            throw new RuntimeException('must not be called on a cache hit');
        });

        self::assertSame(0, $calls);
        self::assertSame('App\\C', $resolved['router']->match('GET', '/x')->route->controllerClass);
    }

    public function test_resolve_cli_uses_the_cache_and_never_invokes_compile_on_a_hit(): void
    {
        $this->publish($this->validCompiledCache());

        $calls = 0;
        $resolved = BootSequence::resolveCli(new CacheStore($this->directory), function () use (&$calls): CompiledCache {
            $calls++;

            throw new RuntimeException('must not be called on a cache hit');
        });

        self::assertSame(0, $calls);
        self::assertSame('App\\C', $resolved['registry']->findCommand('app:x')?->controllerClass);
    }

    /**
     * On a genuine cache miss, the compiler runs exactly once, its
     * result is published, and every plugin instance in the returned
     * bundle was constructed exactly once too — never once to validate
     * and again to actually use.
     */
    public function test_resolve_http_compiles_exactly_once_on_a_miss_and_reconstructs_plugins_exactly_once(): void
    {
        $store = new CacheStore($this->directory);
        $cache = $this->validCompiledCache();
        $withCountingPlugin = new PluginCache([CountingCacheableDiscovery::class => []]);
        $compiled = new CompiledCache($cache->http, $cache->commands, $cache->events, $withCountingPlugin);

        $calls = 0;
        $resolved = BootSequence::resolveHttp($store, function () use (&$calls, $compiled): CompiledCache {
            $calls++;

            return $compiled;
        });

        self::assertSame(1, $calls);
        self::assertSame(1, CountingCacheableDiscovery::$constructions);
        self::assertInstanceOf(CountingCacheableDiscovery::class, $resolved['pluginInstances'][CountingCacheableDiscovery::class]);

        // The publish landed: a fresh store finds it as a hit.
        self::assertNotNull(BootSequence::loadHttpFromCache(new CacheStore($this->directory)));
    }

    public function test_resolve_cli_compiles_exactly_once_on_a_miss_and_publishes_the_result(): void
    {
        $store = new CacheStore($this->directory);
        $compiled = $this->validCompiledCache();

        $calls = 0;
        $resolved = BootSequence::resolveCli($store, function () use (&$calls, $compiled): CompiledCache {
            $calls++;

            return $compiled;
        });

        self::assertSame(1, $calls);
        self::assertSame('App\\C', $resolved['registry']->findCommand('app:x')?->controllerClass);
        self::assertNotNull(BootSequence::loadCliFromCache(new CacheStore($this->directory)));
    }

    /**
     * The other decisive proof: a fresh compiler failure is a real,
     * uncaught error — never silently reinterpreted as "another cache
     * miss, try compiling again."
     */
    public function test_resolve_http_propagates_a_compiler_failure_without_retrying(): void
    {
        $store = new CacheStore($this->directory);

        $calls = 0;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the compiler itself is broken');

        try {
            BootSequence::resolveHttp($store, function () use (&$calls): CompiledCache {
                $calls++;

                throw new RuntimeException('the compiler itself is broken');
            });
        } finally {
            self::assertSame(1, $calls, 'the compiler must be invoked exactly once, never retried');
            self::assertFileDoesNotExist($store->path(), 'a failed compile must never publish anything');
        }
    }

    public function test_resolve_cli_propagates_a_compiler_failure_without_retrying(): void
    {
        $store = new CacheStore($this->directory);

        $calls = 0;
        $this->expectException(RuntimeException::class);

        try {
            BootSequence::resolveCli($store, function () use (&$calls): CompiledCache {
                $calls++;

                throw new RuntimeException('the compiler itself is broken');
            });
        } finally {
            self::assertSame(1, $calls, 'the compiler must be invoked exactly once, never retried');
        }
    }

    /**
     * The decisive proof reconstruction happens before the
     * publish: a fresh compile whose data fails to reconstruct must
     * never publish anything at all, since a later process reading a
     * published-but-broken artifact would hit the identical failure and
     * recompile into it again, forever.
     */
    public function test_resolve_http_propagates_a_reconstruction_failure_from_a_fresh_compile_and_publishes_nothing(): void
    {
        $store = new CacheStore($this->directory);
        $compiled = $this->compiledCacheWithUnreconstructablePlugin();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('StrictCacheableDiscovery: malformed cached data.');

        try {
            BootSequence::resolveHttp($store, fn (): CompiledCache => $compiled);
        } finally {
            self::assertFileDoesNotExist($store->path(), 'a failed fresh reconstruction must never publish anything');
        }
    }

    public function test_resolve_cli_propagates_a_reconstruction_failure_from_a_fresh_compile_and_publishes_nothing(): void
    {
        $store = new CacheStore($this->directory);
        $compiled = $this->compiledCacheWithUnreconstructablePlugin();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('StrictCacheableDiscovery: malformed cached data.');

        try {
            BootSequence::resolveCli($store, fn (): CompiledCache => $compiled);
        } finally {
            self::assertFileDoesNotExist($store->path(), 'a failed fresh reconstruction must never publish anything');
        }
    }

    /**
     * A machine that will not take the file has not made the compiled
     * value wrong, and this process already holds it — so the boot keeps
     * its own result and reports the failure once, rather than turning
     * an unwritable directory into an outage.
     */
    public function test_a_publish_failure_still_returns_the_compiled_value_and_reports_itself_once(): void
    {
        // A directory where the artifact belongs makes rename() fail with
        // no permission games — and a directory this test can portably
        // create, unlike an unwritable one under a root-run container.
        mkdir($this->directory . '/' . CacheStore::ARTIFACT_FILENAME, 0775, true);

        $log = sys_get_temp_dir() . '/kinetis_boot_sequence_log_' . bin2hex(random_bytes(8));
        $previousLog = ini_get('error_log');
        ini_set('error_log', $log);

        try {
            $resolved = BootSequence::resolveHttp(
                new CacheStore($this->directory),
                fn (): CompiledCache => $this->validCompiledCache(),
            );

            self::assertSame('App\\C', $resolved['router']->match('GET', '/x')->route->controllerClass);

            $reported = file_exists($log) ? file_get_contents($log) : '';
            self::assertStringContainsString('could not publish', $reported);
            self::assertSame(1, substr_count($reported, 'could not publish'), 'one boot reports once');
        } finally {
            ini_set('error_log', $previousLog === false ? '' : $previousLog);
            @unlink($log);
        }
    }

    /**
     * A live object in the compiled data is a defect in the compile, not
     * a failure to persist it, so it propagates rather than being
     * absorbed the way a publish failure is — this boot's value is the
     * wrong one to carry on with.
     */
    public function test_an_unexportable_fresh_compile_propagates(): void
    {
        $cache = $this->validCompiledCache();
        $poisoned = new HttpCache(
            routes: $cache->http->routes,
            httpBindingPlans: [],
            hydrationPlans: [
                'App\\Dto' => [
                    'className' => 'App\\Dto',
                    'hasConstructor' => true,
                    'parameters' => [['name' => 'since', 'defaultValue' => new \DateTimeImmutable()]],
                ],
            ],
            globalMiddleware: [],
            openApiMiddleware: [],
        );

        $this->expectException(UnexportableArtifactException::class);

        BootSequence::resolveHttp(
            new CacheStore($this->directory),
            fn (): CompiledCache => new CompiledCache($poisoned, $cache->commands, $cache->events, $cache->plugins),
        );
    }

    // --- assertReconstructable(): the whole artifact, in one pass ---

    /**
     * Two commands sharing a name is the shape that separates the two
     * halves exactly: CommandCache::fromArray() accepts it and
     * CommandRegistry::fromArray() rejects it, so an HTTP boot reads
     * such an artifact as a hit — it builds no CommandRegistry at all —
     * while the CLI misses on it. One artifact carries both entry
     * points, so a build validates both and refuses it.
     */
    public function test_a_command_section_only_the_registry_rejects_is_an_http_hit_a_cli_miss_and_a_build_failure(): void
    {
        $cache = $this->validCompiledCache();
        $entry = $cache->commands->commands[0];
        $compiled = new CompiledCache(
            $cache->http,
            new CommandCache([$entry, $entry]),
            $cache->events,
            $cache->plugins,
        );
        $this->publish($compiled);

        self::assertNotNull(BootSequence::loadHttpFromCache(new CacheStore($this->directory)));
        self::assertNull(BootSequence::loadCliFromCache(new CacheStore($this->directory)));

        $this->expectException(InvalidCacheArtifactException::class);
        $this->expectExceptionMessage('duplicate command name "app:x"');

        BootSequence::assertReconstructable($compiled);
    }

    /**
     * The event and plugin sections both entry points share are
     * reconstructed once for the whole artifact, not once per half.
     */
    public function test_assert_reconstructable_reconstructs_a_plugin_exactly_once(): void
    {
        $cache = $this->validCompiledCache();

        BootSequence::assertReconstructable(new CompiledCache(
            $cache->http,
            $cache->commands,
            $cache->events,
            new PluginCache([CountingCacheableDiscovery::class => []]),
        ));

        self::assertSame(1, CountingCacheableDiscovery::$constructions);
    }

    private function compiledCacheWithUnreconstructablePlugin(): CompiledCache
    {
        $cache = $this->validCompiledCache();

        return new CompiledCache(
            $cache->http,
            $cache->commands,
            $cache->events,
            new PluginCache([StrictCacheableDiscovery::class => ['wrong-key' => 'nope']]),
        );
    }
}
