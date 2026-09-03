<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache;

use Kinetis\Cache\BootSequence;
use Kinetis\Cache\CacheFormat;
use Kinetis\Cache\CacheStore;
use Kinetis\Cache\CommandCache;
use Kinetis\Cache\CompiledCache;
use Kinetis\Cache\EventCache;
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
 * half) and resolveHttp()/resolveCli() (the full cache-or-compile
 * decision) against real generated cache files — proving
 * http.php+events.php+plugins.php (commands.php in place of http.php
 * for the CLI) behave as one all-or-none unit, that every runtime
 * object a boot needs (Router/CommandRegistry included, not just the
 * raw DTOs) is reconstructed exactly once inside that unit, and that a
 * genuine defect in a plugin's own reconstruction never gets
 * misclassified as cache corruption. Every test writes a real
 * generation via a real CacheStore, then corrupts exactly one real
 * file on disk before reading it back — not a mock of any fromArray()
 * method.
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
        CacheStore::destroy($this->directory);
    }

    private function validCompiledCache(): CompiledCache
    {
        $http = new HttpCache(
            formatVersion: CacheFormat::VERSION,
            routes: [['httpMethod' => 'GET', 'pathTemplate' => '/x', 'controllerClass' => 'App\\C', 'controllerMethod' => 'm', 'status' => 200, 'middleware' => []]],
            httpBindingPlans: [],
            hydrationPlans: [],
            globalMiddleware: [],
            openApiMiddleware: [],
            compiledAt: '2026-01-01T00:00:00+00:00',
        );
        $commands = new CommandCache(
            formatVersion: CacheFormat::VERSION,
            commands: [['name' => 'app:x', 'description' => '', 'controllerClass' => 'App\\C', 'controllerMethod' => 'm', 'takesArguments' => false, 'bootstrap' => true]],
            compiledAt: '2026-01-01T00:00:00+00:00',
        );
        $events = new EventCache(
            formatVersion: CacheFormat::VERSION,
            listeners: ['App\\SomeEvent' => [['class' => 'App\\SomeListener', 'method' => 'handle', 'priority' => 50, 'queued' => false]]],
            compiledAt: '2026-01-01T00:00:00+00:00',
        );
        $plugins = new PluginCache(
            formatVersion: CacheFormat::VERSION,
            data: [StrictCacheableDiscovery::class => ['value' => 'ok']],
            compiledAt: '2026-01-01T00:00:00+00:00',
        );

        return new CompiledCache($http, $commands, $events, $plugins);
    }

    /**
     * @return non-empty-string the pinned generation's own directory
     */
    private function publish(CacheStore $store, CompiledCache $cache): string
    {
        $store->writeAll($cache);
        $directory = $store->activeGenerationDirectory();
        self::assertIsString($directory, 'writeAll() must publish a real generation before this helper is used');

        return $directory;
    }

    // --- loadHttpFromCache()/loadCliFromCache(): the cache-hit half ---

    public function test_no_cache_at_all_is_a_miss_for_both_bundles(): void
    {
        $store = new CacheStore($this->directory);

        self::assertNull(BootSequence::loadHttpFromCache($store));
        self::assertNull(BootSequence::loadCliFromCache($store));
    }

    public function test_a_fully_valid_generation_yields_both_bundles_with_reconstructed_runtime_objects(): void
    {
        $store = new CacheStore($this->directory);
        $this->publish($store, $this->validCompiledCache());

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

        $cli = BootSequence::loadCliFromCache(new CacheStore($this->directory));
        self::assertNotNull($cli);
        self::assertInstanceOf(CommandRegistry::class, $cli['registry']);
        self::assertSame('App\\C', $cli['registry']->findCommand('app:x')?->controllerClass);
        self::assertSame(['App\\SomeListener', 'handle'], [
            $cli['listenerRegistry']->listenersFor('App\\SomeEvent')[0]['class'],
            $cli['listenerRegistry']->listenersFor('App\\SomeEvent')[0]['method'],
        ]);
        self::assertInstanceOf(StrictCacheableDiscovery::class, $cli['pluginInstances'][StrictCacheableDiscovery::class]);
    }

    /**
     * A valid http.php alone must not be enough to call this a hit:
     * events.php belongs to the same generation and must be present too.
     */
    public function test_a_missing_events_php_is_a_miss_for_the_http_bundle_even_though_http_php_is_valid(): void
    {
        $store = new CacheStore($this->directory);
        $generationDirectory = $this->publish($store, $this->validCompiledCache());
        unlink($generationDirectory . '/events.php');

        self::assertNull(BootSequence::loadHttpFromCache(new CacheStore($this->directory)));
    }

    public function test_a_missing_events_php_is_a_miss_for_the_cli_bundle_even_though_commands_php_is_valid(): void
    {
        $store = new CacheStore($this->directory);
        $generationDirectory = $this->publish($store, $this->validCompiledCache());
        unlink($generationDirectory . '/events.php');

        self::assertNull(BootSequence::loadCliFromCache(new CacheStore($this->directory)));
    }

    /**
     * A stale-format plugins.php — CacheStore::loadSection()'s own
     * formatVersion check already returns null for this, cleanly, with
     * no exception at all; this proves that null is what makes the
     * *whole* bundle a miss too, not just the one section.
     */
    public function test_a_wrong_format_version_in_plugins_php_is_a_miss_for_the_http_bundle(): void
    {
        $store = new CacheStore($this->directory);
        $generationDirectory = $this->publish($store, $this->validCompiledCache());

        $wrongVersion = ['formatVersion' => CacheFormat::VERSION + 1, 'data' => [], 'compiledAt' => 'x'];
        file_put_contents($generationDirectory . '/plugins.php', "<?php\n\nreturn " . var_export($wrongVersion, true) . ";\n");

        self::assertNull(BootSequence::loadHttpFromCache(new CacheStore($this->directory)));
    }

    /**
     * A structurally malformed event registry — a duplicate {class,
     * method} pair, which EventListenerRegistry::fromArray() rejects by
     * throwing InvalidListenerException (see its own docblock). A
     * generation carrying one must be classified as corrupt and turned
     * into a clean miss here, never an uncaught fatal at boot.
     */
    public function test_a_structurally_malformed_event_registry_is_a_miss_not_an_uncaught_exception(): void
    {
        $store = new CacheStore($this->directory);
        $cache = $this->validCompiledCache();
        $corruptEvents = new EventCache(
            formatVersion: CacheFormat::VERSION,
            listeners: [
                'App\\SomeEvent' => [
                    ['class' => 'App\\SomeListener', 'method' => 'handle', 'priority' => 50, 'queued' => false],
                    ['class' => 'App\\SomeListener', 'method' => 'handle', 'priority' => 10, 'queued' => false],
                ],
            ],
            compiledAt: '2026-01-01T00:00:00+00:00',
        );
        $this->publish($store, new CompiledCache($cache->http, $cache->commands, $corruptEvents, $cache->plugins));

        self::assertNull(BootSequence::loadHttpFromCache(new CacheStore($this->directory)));
        self::assertNull(BootSequence::loadCliFromCache(new CacheStore($this->directory)));
    }

    /**
     * The same classification, triggered from the plugins.php side
     * instead of events.php — a package's own fromArray() rejecting its
     * own malformed cached data (via an exception implementing
     * CacheArtifactExceptionInterface, per that interface's own
     * contract) is exactly the same class of "this generation is
     * corrupt" signal as EventListenerRegistry's.
     */
    public function test_a_plugins_own_malformed_cached_data_is_a_miss_not_an_uncaught_exception(): void
    {
        $store = new CacheStore($this->directory);
        $cache = $this->validCompiledCache();
        $corruptPlugins = new PluginCache(
            formatVersion: CacheFormat::VERSION,
            data: [StrictCacheableDiscovery::class => ['wrong-key' => 'nope']],
            compiledAt: '2026-01-01T00:00:00+00:00',
        );
        $this->publish($store, new CompiledCache($cache->http, $cache->commands, $cache->events, $corruptPlugins));

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
        $store = new CacheStore($this->directory);
        $cache = $this->validCompiledCache();
        $buggyPlugins = new PluginCache(
            formatVersion: CacheFormat::VERSION,
            data: [BuggyCacheableDiscovery::class => []],
            compiledAt: '2026-01-01T00:00:00+00:00',
        );
        $this->publish($store, new CompiledCache($cache->http, $cache->commands, $cache->events, $buggyPlugins));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('BuggyCacheableDiscovery: a genuine defect, not a data-shape problem.');

        BootSequence::loadHttpFromCache(new CacheStore($this->directory));
    }

    /**
     * A malformed top-level field on http.php itself (right format
     * version, but a field missing or wrong-typed) must also be a miss
     * — not an uncaught TypeError escaping from inside
     * CacheStore::loadHttp()/HttpCache::fromArray(), before
     * loadHttpFromCache()'s own try block would otherwise have started.
     */
    public function test_a_malformed_top_level_field_in_http_php_is_a_miss(): void
    {
        $store = new CacheStore($this->directory);
        $generationDirectory = $this->publish($store, $this->validCompiledCache());

        $data = $this->validCompiledCache()->http->toArray();
        unset($data['globalMiddleware']);
        file_put_contents($generationDirectory . '/http.php', "<?php\n\nreturn " . var_export($data, true) . ";\n");

        self::assertNull(BootSequence::loadHttpFromCache(new CacheStore($this->directory)));
    }

    /**
     * The same, for commands.php.
     */
    public function test_a_malformed_top_level_field_in_commands_php_is_a_miss(): void
    {
        $store = new CacheStore($this->directory);
        $generationDirectory = $this->publish($store, $this->validCompiledCache());

        $data = $this->validCompiledCache()->commands->toArray();
        unset($data['packageBootstraps']);
        file_put_contents($generationDirectory . '/commands.php', "<?php\n\nreturn " . var_export($data, true) . ";\n");

        self::assertNull(BootSequence::loadCliFromCache(new CacheStore($this->directory)));
    }

    /**
     * A malformed *entry* within an otherwise well-shaped http.php — a
     * route missing its own "httpMethod" — must be a miss too, not an
     * uncaught TypeError from inside Router::fromArray().
     */
    public function test_a_malformed_route_entry_in_http_php_is_a_miss(): void
    {
        $store = new CacheStore($this->directory);
        $generationDirectory = $this->publish($store, $this->validCompiledCache());

        $data = $this->validCompiledCache()->http->toArray();
        unset($data['routes'][0]['httpMethod']);
        file_put_contents($generationDirectory . '/http.php', "<?php\n\nreturn " . var_export($data, true) . ";\n");

        self::assertNull(BootSequence::loadHttpFromCache(new CacheStore($this->directory)));
    }

    /**
     * The same, for a command entry missing "bootstrap" in commands.php.
     */
    public function test_a_malformed_command_entry_in_commands_php_is_a_miss(): void
    {
        $store = new CacheStore($this->directory);
        $generationDirectory = $this->publish($store, $this->validCompiledCache());

        $data = $this->validCompiledCache()->commands->toArray();
        unset($data['commands'][0]['bootstrap']);
        file_put_contents($generationDirectory . '/commands.php', "<?php\n\nreturn " . var_export($data, true) . ";\n");

        self::assertNull(BootSequence::loadCliFromCache(new CacheStore($this->directory)));
    }

    /**
     * Laziness proof, half one: the HTTP bundle must never require
     * commands.php at all — corrupting it beyond recognition (not even
     * valid PHP) must not affect loadHttpFromCache() in the slightest.
     */
    public function test_http_bundle_never_reads_commands_php(): void
    {
        $store = new CacheStore($this->directory);
        $generationDirectory = $this->publish($store, $this->validCompiledCache());
        file_put_contents($generationDirectory . '/commands.php', "<?php\n\nthis is not valid PHP at all {{{\n");

        $http = BootSequence::loadHttpFromCache(new CacheStore($this->directory));
        self::assertNotNull($http);
        self::assertSame('App\\C', $http['router']->match('GET', '/x')->route->controllerClass);
    }

    /**
     * Laziness proof, half two: the dual of the above for the CLI
     * bundle and http.php.
     */
    public function test_cli_bundle_never_reads_http_php(): void
    {
        $store = new CacheStore($this->directory);
        $generationDirectory = $this->publish($store, $this->validCompiledCache());
        file_put_contents($generationDirectory . '/http.php', "<?php\n\nthis is not valid PHP at all {{{\n");

        $cli = BootSequence::loadCliFromCache(new CacheStore($this->directory));
        self::assertNotNull($cli);
        self::assertSame('App\\C', $cli['registry']->findCommand('app:x')?->controllerClass);
    }

    /**
     * A syntax-corrupt http.php (a truncated write, corruption, hand
     * tampering — never a shape produced by writeAll() itself) must
     * also be a clean miss: require()'s own ParseError, thrown while
     * reconstructing data read directly off disk, is exactly the same
     * class of "this generation is unusable" signal as a missing file
     * or a wrong format version, not an uncaught fatal escaping this
     * method.
     */
    public function test_a_syntax_corrupt_http_php_is_a_miss_not_an_uncaught_parse_error(): void
    {
        $store = new CacheStore($this->directory);
        $generationDirectory = $this->publish($store, $this->validCompiledCache());
        file_put_contents($generationDirectory . '/http.php', "<?php\n\nthis is not valid PHP at all {{{\n");

        self::assertNull(BootSequence::loadHttpFromCache(new CacheStore($this->directory)));
    }

    /**
     * The same, for commands.php and the CLI bundle.
     */
    public function test_a_syntax_corrupt_commands_php_is_a_miss_not_an_uncaught_parse_error(): void
    {
        $store = new CacheStore($this->directory);
        $generationDirectory = $this->publish($store, $this->validCompiledCache());
        file_put_contents($generationDirectory . '/commands.php', "<?php\n\nthis is not valid PHP at all {{{\n");

        self::assertNull(BootSequence::loadCliFromCache(new CacheStore($this->directory)));
    }

    /**
     * A rejected bundle is a pure decision, not a retry loop: the same
     * still-corrupt generation, read twice in a row through the same
     * pinned CacheStore instance, returns null both times — never a
     * second attempt that behaves differently, and never an exception
     * escaping on either call.
     */
    public function test_a_rejected_bundle_returns_null_deterministically_not_a_retry_loop(): void
    {
        $store = new CacheStore($this->directory);
        $generationDirectory = $this->publish($store, $this->validCompiledCache());
        unlink($generationDirectory . '/events.php');

        $reader = new CacheStore($this->directory);
        self::assertNull(BootSequence::loadHttpFromCache($reader));
        self::assertNull(BootSequence::loadHttpFromCache($reader));
    }

    // --- resolveHttp()/resolveCli(): the full cache-or-compile decision ---

    public function test_resolve_http_uses_the_cache_and_never_invokes_compile_on_a_hit(): void
    {
        $store = new CacheStore($this->directory);
        $this->publish($store, $this->validCompiledCache());

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
        $store = new CacheStore($this->directory);
        $this->publish($store, $this->validCompiledCache());

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
        $withCountingPlugin = new PluginCache(
            formatVersion: CacheFormat::VERSION,
            data: [CountingCacheableDiscovery::class => []],
            compiledAt: '2026-01-01T00:00:00+00:00',
        );
        $compiled = new CompiledCache($cache->http, $cache->commands, $cache->events, $withCountingPlugin);

        $calls = 0;
        $resolved = BootSequence::resolveHttp($store, function () use (&$calls, $compiled): CompiledCache {
            $calls++;

            return $compiled;
        });

        self::assertSame(1, $calls);
        self::assertSame(1, CountingCacheableDiscovery::$constructions);
        self::assertInstanceOf(CountingCacheableDiscovery::class, $resolved['pluginInstances'][CountingCacheableDiscovery::class]);

        // writeAll() really happened: a fresh store now finds it as a hit.
        $hit = BootSequence::loadHttpFromCache(new CacheStore($this->directory));
        self::assertNotNull($hit);
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
            self::assertNull($store->activeGenerationDirectory(), 'a failed compile must never publish anything');
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
     * A malformed fresh compile — the compiler callback itself
     * succeeds, but produces data that fails to reconstruct (a plugin
     * rejecting its own freshly-compiled data, say) — is also a real,
     * uncaught failure: there is no cache artifact to blame it on, so
     * nothing here classifies it as a miss to retry.
     */
    /**
     * The decisive proof reconstruction genuinely happens before
     * writeAll(): a fresh compile whose data fails to reconstruct must
     * never publish anything at all — not even the pieces that *would*
     * have reconstructed successfully — since a later process reading
     * a published-but-broken generation would just hit the identical
     * failure and recompile into it again, forever.
     */
    public function test_resolve_http_propagates_a_reconstruction_failure_from_a_fresh_compile_and_publishes_nothing(): void
    {
        $store = new CacheStore($this->directory);
        $cache = $this->validCompiledCache();
        $brokenPlugins = new PluginCache(
            formatVersion: CacheFormat::VERSION,
            data: [StrictCacheableDiscovery::class => ['wrong-key' => 'nope']],
            compiledAt: '2026-01-01T00:00:00+00:00',
        );
        $compiled = new CompiledCache($cache->http, $cache->commands, $cache->events, $brokenPlugins);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('StrictCacheableDiscovery: malformed cached data.');

        try {
            BootSequence::resolveHttp($store, fn (): CompiledCache => $compiled);
        } finally {
            self::assertNull($store->activeGenerationDirectory(), 'a failed fresh reconstruction must never publish anything');
        }
    }

    public function test_resolve_cli_propagates_a_reconstruction_failure_from_a_fresh_compile_and_publishes_nothing(): void
    {
        $store = new CacheStore($this->directory);
        $cache = $this->validCompiledCache();
        $brokenPlugins = new PluginCache(
            formatVersion: CacheFormat::VERSION,
            data: [StrictCacheableDiscovery::class => ['wrong-key' => 'nope']],
            compiledAt: '2026-01-01T00:00:00+00:00',
        );
        $compiled = new CompiledCache($cache->http, $cache->commands, $cache->events, $brokenPlugins);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('StrictCacheableDiscovery: malformed cached data.');

        try {
            BootSequence::resolveCli($store, fn (): CompiledCache => $compiled);
        } finally {
            self::assertNull($store->activeGenerationDirectory(), 'a failed fresh reconstruction must never publish anything');
        }
    }
}
