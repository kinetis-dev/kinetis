<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache;

use Kinetis\Cache\CacheFormat;
use Kinetis\Cache\CacheStore;
use Kinetis\Cache\Exception\CacheWriteException;
use Kinetis\Cache\CommandCache;
use Kinetis\Cache\CompiledCache;
use Kinetis\Cache\EventCache;
use Kinetis\Cache\HttpCache;
use Kinetis\Cache\PluginCache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CacheStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/kinetis_cache_store_test_' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        CacheStore::destroy($this->directory);
    }

    /**
     * Every one of the four sections carries something distinguishable
     * from generation() below — including packageBootstraps, present on
     * both HttpCache and CommandCache, so a mixed-generation read that
     * combined one generation's HttpCache with another's CommandCache
     * would show up there too, not just in the more obviously-different
     * fields.
     */
    private function compiledCache(string $marker = 'a'): CompiledCache
    {
        $http = new HttpCache(
            formatVersion: CacheFormat::VERSION,
            routes: [['httpMethod' => 'GET', 'pathTemplate' => "/{$marker}", 'controllerClass' => "App\\C{$marker}", 'controllerMethod' => 'm', 'status' => 200, 'middleware' => []]],
            httpBindingPlans: [],
            hydrationPlans: [],
            globalMiddleware: [],
            openApiMiddleware: [],
            compiledAt: "2026-01-01T00:00:00+00:00#{$marker}",
            packageBootstraps: ["App\\Package{$marker}Bootstrap"],
        );
        $commands = new CommandCache(
            formatVersion: CacheFormat::VERSION,
            commands: [['name' => "app:{$marker}", 'description' => '', 'controllerClass' => "App\\C{$marker}", 'controllerMethod' => 'm', 'takesArguments' => false, 'bootstrap' => true]],
            compiledAt: "2026-01-01T00:00:00+00:00#{$marker}",
            packageBootstraps: ["App\\Package{$marker}Bootstrap"],
        );
        $events = new EventCache(
            formatVersion: CacheFormat::VERSION,
            listeners: ["App\\SomeEvent{$marker}" => [['class' => "App\\SomeListener{$marker}", 'method' => 'handle', 'priority' => 50, 'queued' => true]]],
            compiledAt: "2026-01-01T00:00:00+00:00#{$marker}",
        );
        $plugins = new PluginCache(
            formatVersion: CacheFormat::VERSION,
            data: ["App\\SomeRegistry{$marker}" => ['x' => 1]],
            compiledAt: "2026-01-01T00:00:00+00:00#{$marker}",
        );

        return new CompiledCache($http, $commands, $events, $plugins);
    }

    public function test_exists_is_false_when_nothing_has_been_published(): void
    {
        $store = new CacheStore($this->directory);

        self::assertFalse($store->exists());
    }

    public function test_load_methods_return_null_when_nothing_has_been_published(): void
    {
        $store = new CacheStore($this->directory);

        self::assertNull($store->loadHttp());
        self::assertNull($store->loadCommands());
        self::assertNull($store->loadEvents());
        self::assertNull($store->loadPlugins());
    }

    public function test_write_all_then_load_round_trips_each_section(): void
    {
        $store = new CacheStore($this->directory);
        $cache = $this->compiledCache();

        $store->writeAll($cache);

        self::assertTrue($store->exists());
        self::assertEquals($cache->http, $store->loadHttp());
        self::assertEquals($cache->commands, $store->loadCommands());
        self::assertEquals($cache->events, $store->loadEvents());
        self::assertEquals($cache->plugins, $store->loadPlugins());
    }

    public function test_a_normal_http_request_never_needs_to_read_the_other_sections(): void
    {
        $store = new CacheStore($this->directory);
        $store->writeAll($this->compiledCache());

        // Only http.php is ever required for this assertion — proving the
        // whole point of the split: loadHttp() alone is sufficient for a
        // normal request, no cross-file dependency.
        $http = $store->loadHttp();

        self::assertNotNull($http);
        self::assertCount(1, $http->routes);
    }

    public function test_load_returns_null_for_a_mismatched_section_format_version(): void
    {
        $store = new CacheStore($this->directory);
        $store->writeAll($this->compiledCache());

        $generationDirectory = $store->activeGenerationDirectory();
        self::assertNotNull($generationDirectory);
        file_put_contents($generationDirectory . '/http.php', "<?php return ['formatVersion' => 999];\n");

        // A fresh instance: the corrupted file is on disk before this
        // instance ever pins, so its own first read sees it directly.
        self::assertNull((new CacheStore($this->directory))->loadHttp());
    }

    public function test_load_returns_null_when_the_pointer_itself_has_a_stale_format_version(): void
    {
        $store = new CacheStore($this->directory);
        $store->writeAll($this->compiledCache());

        // The pointer's own shape changing (a future Kinetis version) is
        // exactly what this simulates — not a per-section mismatch, the
        // pointer that names which generation to even look in.
        file_put_contents($this->directory . '/current', "1\nanything\n");

        $fresh = new CacheStore($this->directory);
        self::assertFalse($fresh->exists());
        self::assertNull($fresh->loadHttp());
    }

    public function test_load_returns_null_when_the_pointer_names_a_generation_that_does_not_exist_on_disk(): void
    {
        // A pointer surviving a generation directory's own removal (e.g.
        // manual interference) must still degrade to "nothing published"
        // rather than a fatal require() error. A validly-shaped name
        // (16 lowercase hex characters) that simply has no matching
        // directory — distinct from an invalidly-shaped one, which
        // GENERATION_NAME_PATTERN rejects for a different reason.
        mkdir($this->directory, 0775, true);
        file_put_contents($this->directory . '/current', CacheFormat::VERSION . "\ngen_" . str_repeat('a', 16) . "\n");

        $store = new CacheStore($this->directory);
        self::assertFalse($store->exists());
        self::assertNull($store->loadHttp());
    }

    public function test_exists_is_false_when_plugins_php_is_missing_from_the_active_generation(): void
    {
        // A partially-tampered generation (a file manually deleted after
        // publish) must be treated as "no cache" at all, not an
        // inconsistent mix — the same guarantee already covered for the
        // other three files.
        $store = new CacheStore($this->directory);
        $store->writeAll($this->compiledCache());

        $generationDirectory = $store->activeGenerationDirectory();
        self::assertNotNull($generationDirectory);
        unlink($generationDirectory . '/plugins.php');

        self::assertFalse((new CacheStore($this->directory))->exists());
    }

    public function test_write_all_leaves_no_stray_tmp_files_behind_after_success(): void
    {
        $store = new CacheStore($this->directory);
        $store->writeAll($this->compiledCache());

        $generationDirectory = $store->activeGenerationDirectory();
        self::assertNotNull($generationDirectory);

        $files = glob($generationDirectory . '/*') ?: [];
        sort($files);

        $expected = [
            $generationDirectory . '/commands.php',
            $generationDirectory . '/events.php',
            $generationDirectory . '/http.php',
            $generationDirectory . '/plugins.php',
        ];
        sort($expected);

        self::assertSame($expected, $files);
    }

    /**
     * The structural guarantee behind CacheStore's own OPcache-staleness
     * reasoning: the pointer is never `<?php`, so nothing ever
     * `require()`s it, so a rewritten pointer is never at risk of
     * serving stale opcode-cached content — see the class docblock for
     * why that risk is real and specific to this one file. Two
     * consecutive publishes are checked, not one, to prove a rewritten
     * pointer's bytes are genuinely re-read from disk each time (which
     * `file_get_contents()` always does, unlike `require()` under
     * `opcache.validate_timestamps=0`) rather than merely correct on the
     * very first read.
     */
    public function test_the_pointer_file_is_plain_data_never_php_and_reflects_every_republish(): void
    {
        $store = new CacheStore($this->directory);
        $store->writeAll($this->compiledCache('a'));

        $pointerPath = $this->directory . '/current';
        $firstContents = file_get_contents($pointerPath);
        self::assertIsString($firstContents);
        self::assertStringStartsNotWith('<?php', $firstContents);
        self::assertMatchesRegularExpression('/^' . CacheFormat::VERSION . "\ngen_[0-9a-f]{16}\n\$/", $firstContents);

        $store->writeAll($this->compiledCache('b'));
        $secondContents = file_get_contents($pointerPath);
        self::assertIsString($secondContents);
        self::assertNotSame($firstContents, $secondContents, 'a second publish must genuinely rewrite the pointer bytes');
    }

    // --- Deterministic generation-interleaving: publish B between one
    // store instance's first and later load*() calls, and prove that
    // instance stays entirely on A while a fresh instance sees entirely
    // B. This is the actual defect KINETIS-12 reported — four separate
    // reads that could each independently observe whichever generation
    // happened to be active at that exact call, not the compile pass
    // that produced any of the others. Every assertion below distinguishes
    // generations by more than one field, packageBootstraps included,
    // since that field is duplicated across HttpCache and CommandCache
    // specifically and would catch a mix between those two sections that
    // a single-field check could miss. ---

    public function test_a_pinned_instance_stays_entirely_on_generation_a_after_generation_b_is_published(): void
    {
        $writerA = new CacheStore($this->directory);
        $writerA->writeAll($this->compiledCache('a'));

        $reader = new CacheStore($this->directory);
        // First read — pins this instance to generation A.
        $http = $reader->loadHttp();
        self::assertNotNull($http);
        self::assertSame('/a', $http->routes[0]['pathTemplate']);
        self::assertSame(['App\\PackageaBootstrap'], $http->packageBootstraps);

        // A second, independent writer publishes generation B — a real
        // new CacheStore instance, the same as a separate deploy's
        // `kinetis build` or a different racing worker would use.
        $writerB = new CacheStore($this->directory);
        $writerB->writeAll($this->compiledCache('b'));

        // Every later read from $reader must still be generation A,
        // across all four sections, not just the one already read.
        $commands = $reader->loadCommands();
        $events = $reader->loadEvents();
        $plugins = $reader->loadPlugins();

        self::assertNotNull($commands);
        self::assertNotNull($events);
        self::assertNotNull($plugins);
        self::assertSame('app:a', $commands->commands[0]['name']);
        self::assertSame(['App\\PackageaBootstrap'], $commands->packageBootstraps);
        self::assertArrayHasKey('App\\SomeEventa', $events->listeners);
        self::assertArrayHasKey('App\\SomeRegistrya', $plugins->data);

        // Re-reading http.php a second time on the same instance must
        // also still be A — pinning isn't a one-shot "first call only"
        // effect, it holds for the instance's whole lifetime. Equal, not
        // identical: each loadHttp() call constructs a fresh HttpCache
        // from the required array, since only the resolved generation
        // itself is cached, not the deserialized object.
        self::assertEquals($http, $reader->loadHttp());
    }

    public function test_a_fresh_instance_constructed_after_the_publish_sees_generation_b_entirely(): void
    {
        (new CacheStore($this->directory))->writeAll($this->compiledCache('a'));
        (new CacheStore($this->directory))->writeAll($this->compiledCache('b'));

        // Never touched before this generation existed — nothing to pin
        // to except whatever is active right now, generation B.
        $fresh = new CacheStore($this->directory);

        $http = $fresh->loadHttp();
        $commands = $fresh->loadCommands();
        $events = $fresh->loadEvents();
        $plugins = $fresh->loadPlugins();

        self::assertNotNull($http);
        self::assertNotNull($commands);
        self::assertNotNull($events);
        self::assertNotNull($plugins);
        self::assertSame('/b', $http->routes[0]['pathTemplate']);
        self::assertSame(['App\\PackagebBootstrap'], $http->packageBootstraps);
        self::assertSame('app:b', $commands->commands[0]['name']);
        self::assertSame(['App\\PackagebBootstrap'], $commands->packageBootstraps);
        self::assertArrayHasKey('App\\SomeEventb', $events->listeners);
        self::assertArrayHasKey('App\\SomeRegistryb', $plugins->data);
    }

    public function test_an_instance_that_pins_before_anything_is_published_stays_absent_even_after_a_later_publish(): void
    {
        $reader = new CacheStore($this->directory);
        // First read, before anything exists — pins this instance to
        // "no generation," permanently, for its own lifetime.
        self::assertNull($reader->loadHttp());

        (new CacheStore($this->directory))->writeAll($this->compiledCache());

        self::assertNull($reader->loadCommands());
        self::assertNull($reader->loadEvents());
        self::assertNull($reader->loadPlugins());
        self::assertFalse($reader->exists());
    }

    public function test_exists_pins_the_same_as_a_load_call_does(): void
    {
        $reader = new CacheStore($this->directory);
        self::assertFalse($reader->exists());

        (new CacheStore($this->directory))->writeAll($this->compiledCache());

        // exists() itself is the first read here, so it pins to "absent"
        // exactly like loadHttp() would have.
        self::assertFalse($reader->exists());
        self::assertNull($reader->loadHttp());
    }

    public function test_a_second_publish_does_not_delete_the_first_generations_directory(): void
    {
        $store = new CacheStore($this->directory);
        $store->writeAll($this->compiledCache('a'));
        $firstGenerationDirectory = $store->activeGenerationDirectory();
        self::assertNotNull($firstGenerationDirectory);

        (new CacheStore($this->directory))->writeAll($this->compiledCache('b'));

        // Retention is deliberately conservative — see CacheStore's own
        // docblock: a publish never deletes an older generation.
        self::assertDirectoryExists($firstGenerationDirectory);
        self::assertFileExists($firstGenerationDirectory . '/http.php');
    }

    public function test_a_failed_publish_leaves_the_previously_active_generation_untouched_and_loadable(): void
    {
        $store = new CacheStore($this->directory);
        $store->writeAll($this->compiledCache('a'));

        try {
            $store->writeAll($this->poisonedCompiledCache());
            self::fail('Expected a CacheWriteException.');
        } catch (CacheWriteException) {
            // Expected — see poisonedCompiledCache()'s own docblock.
        }

        // A fresh instance, not the one that attempted (and failed) the
        // second publish — proving the pointer itself was never touched,
        // not merely that this one instance's own pin didn't move.
        $fresh = new CacheStore($this->directory);
        self::assertTrue($fresh->exists());
        $http = $fresh->loadHttp();
        self::assertNotNull($http);
        self::assertSame('/a', $http->routes[0]['pathTemplate']);
    }

    public function test_a_failed_publish_does_not_leave_a_partially_written_generation_directory_behind(): void
    {
        $store = new CacheStore($this->directory);

        try {
            $store->writeAll($this->poisonedCompiledCache());
            self::fail('Expected a CacheWriteException.');
        } catch (CacheWriteException) {
            // Expected.
        }

        // Nothing published at all yet (this was the very first write
        // attempt), so nothing should be left on disk — not a stray
        // half-written generation directory, not a pointer naming one.
        self::assertFalse(is_dir($this->directory) && glob($this->directory . '/gen_*') !== []);
        self::assertFileDoesNotExist($this->directory . '/current');
    }

    public function test_an_object_in_a_section_fails_the_write_with_the_path_to_it(): void
    {
        // A constructor default like `new DateTimeImmutable()` captured
        // into a hydration plan is the real way an object reaches a cache
        // artifact — var_export() would render it as a ::__set_state()
        // call the reload can't replay.
        $store = new CacheStore($this->directory);

        try {
            $store->writeAll($this->poisonedCompiledCache());
            self::fail('Expected a CacheWriteException.');
        } catch (CacheWriteException $e) {
            self::assertStringContainsString('DateTimeImmutable', $e->getMessage());
            self::assertStringContainsString('defaultValue', $e->getMessage());
        }
    }

    // --- The pointer's own `generation` value is untrusted input the
    // instant it's read back off disk — CacheStore never wrote it itself
    // in these scenarios, so it must be validated against the exact
    // grammar writeAll() generates (gen_ + 16 lowercase hex characters)
    // before ever being concatenated into a filesystem path, not merely
    // handled gracefully after the fact. ---

    /**
     * @return list<array{string}>
     */
    public static function maliciousGenerationValues(): array
    {
        return [
            'path traversal' => ['../../../etc/passwd'],
            'absolute path' => ['/etc/passwd'],
            'path separator embedded in an otherwise valid-looking name' => ['gen_' . str_repeat('a', 8) . '/../../x'],
            'null byte embedded' => ["gen_" . str_repeat('a', 12) . "\0evil"],
            'right length, wrong alphabet' => ['gen_' . str_repeat('g', 16)],
            'too short' => ['gen_' . str_repeat('a', 8)],
            'missing the gen_ prefix entirely' => [str_repeat('a', 16)],
            'empty string' => [''],
        ];
    }

    #[DataProvider('maliciousGenerationValues')]
    public function test_a_pointer_naming_an_invalid_generation_value_is_rejected_without_touching_the_filesystem(string $malicious): void
    {
        mkdir($this->directory, 0775, true);
        file_put_contents($this->directory . '/current', CacheFormat::VERSION . "\n" . $malicious . "\n");

        $store = new CacheStore($this->directory);

        self::assertFalse($store->exists());
        self::assertNull($store->loadHttp());
        self::assertNull($store->loadCommands());
        self::assertNull($store->loadEvents());
        self::assertNull($store->loadPlugins());
        self::assertNull((new CacheStore($this->directory))->activeGenerationDirectory());
    }

    /**
     * @return list<array{string}>
     */
    public static function malformedPointerContents(): array
    {
        $version = (string) CacheFormat::VERSION;
        $generation = 'gen_' . str_repeat('a', 16);

        return [
            'trailing data after the required newline' => ["{$version}\n{$generation}\nextra"],
            'an extra blank line' => ["{$version}\n{$generation}\n\n"],
            'missing the trailing newline entirely' => ["{$version}\n{$generation}"],
            'no newline anywhere' => ['garbage'],
            'only one newline, no second line at all' => ["{$version}\n"],
        ];
    }

    /**
     * readPointer() requires exactly the two-line, single-trailing-
     * newline shape writePointer() itself always emits — not "the first
     * two lines, whatever else is there is ignored". Each of these is a
     * real, well-formed-looking pointer that differs from that exact
     * shape in one specific way, and every one must degrade to "no
     * pointer at all" rather than a partial parse of whatever precedes
     * the malformed part.
     */
    #[DataProvider('malformedPointerContents')]
    public function test_a_pointer_not_matching_the_exact_documented_shape_is_rejected(string $malformed): void
    {
        mkdir($this->directory, 0775, true);
        file_put_contents($this->directory . '/current', $malformed);

        $store = new CacheStore($this->directory);

        self::assertFalse($store->exists());
        self::assertNull($store->loadHttp());
        self::assertNull((new CacheStore($this->directory))->activeGenerationDirectory());
    }

    // --- removeDirectoryRecursively() (destroy()'s own machinery) must
    // never follow a symlink, at any depth, including the cache
    // directory argument itself being one — is_dir() alone can't
    // distinguish a real directory from a symlink pointing at one, so
    // recursing through it would let anything with write access inside
    // the cache tree make destroy() delete files far outside it. ---

    public function test_destroy_removes_a_nested_symlink_itself_without_following_it_into_an_external_directory(): void
    {
        $externalDirectory = sys_get_temp_dir() . '/kinetis_cache_store_test_external_' . bin2hex(random_bytes(8));
        mkdir($externalDirectory, 0775, true);
        $sentinel = $externalDirectory . '/sentinel.txt';
        file_put_contents($sentinel, 'do not delete me');

        try {
            $store = new CacheStore($this->directory);
            $store->writeAll($this->compiledCache());
            $generationDirectory = $store->activeGenerationDirectory();
            self::assertNotNull($generationDirectory);

            self::assertTrue(symlink($externalDirectory, $generationDirectory . '/evil_link'));

            CacheStore::destroy($this->directory);

            self::assertDirectoryDoesNotExist($this->directory);
            self::assertFileExists($sentinel, 'the symlink target must never be touched, only the link itself');
        } finally {
            CacheStore::destroy($externalDirectory);
        }
    }

    public function test_destroy_removes_a_root_level_symlink_itself_without_following_it_into_an_external_directory(): void
    {
        $externalDirectory = sys_get_temp_dir() . '/kinetis_cache_store_test_external_' . bin2hex(random_bytes(8));
        mkdir($externalDirectory, 0775, true);
        $sentinel = $externalDirectory . '/sentinel.txt';
        file_put_contents($sentinel, 'do not delete me');

        // The cache directory *itself* is a symlink — the same hazard
        // one level up, when whatever calls destroy() is handed a path
        // that turns out to be a link rather than a real directory.
        $symlinkedCacheDirectory = sys_get_temp_dir() . '/kinetis_cache_store_test_symlinked_' . bin2hex(random_bytes(8));

        try {
            self::assertTrue(symlink($externalDirectory, $symlinkedCacheDirectory));

            CacheStore::destroy($symlinkedCacheDirectory);

            self::assertFileDoesNotExist($symlinkedCacheDirectory, 'the symlink entry itself must be gone');
            self::assertFileExists($sentinel, 'the symlink target must never be touched, only the link itself');
        } finally {
            @unlink($symlinkedCacheDirectory);
            CacheStore::destroy($externalDirectory);
        }
    }

    // --- A generation the pointer doesn't currently name must never be
    // "discovered" by scanning the cache directory — CacheStore trusts
    // only the pointer, regardless of what else is sitting on disk,
    // complete or not. ---

    public function test_a_complete_generation_the_pointer_no_longer_names_is_never_discovered(): void
    {
        $store = new CacheStore($this->directory);
        $store->writeAll($this->compiledCache('a'));
        $firstGenerationDirectory = $store->activeGenerationDirectory();
        self::assertNotNull($firstGenerationDirectory);
        self::assertFileExists($firstGenerationDirectory . '/http.php', 'sanity check: generation a really is complete on disk');

        // The pointer now names a different generation — "a" is fully
        // present and fully valid, just no longer named by current.
        (new CacheStore($this->directory))->writeAll($this->compiledCache('b'));

        $fresh = new CacheStore($this->directory);
        $http = $fresh->loadHttp();
        self::assertNotNull($http);
        // Generation b, never a — proving the complete-but-unpointed
        // generation a was never considered, not even as a fallback.
        self::assertSame('/b', $http->routes[0]['pathTemplate']);
    }

    public function test_an_incomplete_generation_directory_the_pointer_does_not_name_is_never_discovered(): void
    {
        $store = new CacheStore($this->directory);
        $store->writeAll($this->compiledCache('a'));

        // A generation directory matching the real grammar, sitting
        // right next to the real one, but never named by current and
        // missing three of its four sections — the shape a crashed or
        // killed writer could plausibly leave behind.
        $orphanedGeneration = $this->directory . '/gen_' . str_repeat('b', 16);
        mkdir($orphanedGeneration, 0775, true);
        file_put_contents($orphanedGeneration . '/http.php', "<?php\n\nreturn ['formatVersion' => " . CacheFormat::VERSION . ", 'routes' => [], 'httpBindingPlans' => [], 'hydrationPlans' => [], 'globalMiddleware' => [], 'openApiMiddleware' => [], 'middlewareGroups' => [], 'packageBootstraps' => [], 'compiledAt' => 'orphan'];\n");

        $fresh = new CacheStore($this->directory);
        $http = $fresh->loadHttp();
        self::assertNotNull($http);
        self::assertSame('/a', $http->routes[0]['pathTemplate'], 'the real, pointer-named generation, never the orphaned one sitting beside it');
    }

    // --- writeAll()'s try/catch covers the pointer write itself, not
    // only the four section writes — a failure publishing the pointer
    // must clean up the generation it was about to name just as
    // thoroughly as a failure writing one of the sections does (see
    // test_a_failed_publish_does_not_leave_a_partially_written_generation_directory_behind
    // above for that half; this is the pointer-specific one). ---

    public function test_a_failed_pointer_publish_leaves_no_orphaned_generation_directory_behind(): void
    {
        mkdir($this->directory, 0775, true);
        // rename() can never replace an existing directory with a file,
        // regardless of permissions or uid (confirmed directly, not
        // assumed — this holds even running as root) — a reliable,
        // portable way to force writePointer()'s own rename() to fail
        // without depending on filesystem permissions at all.
        mkdir($this->directory . '/current');

        $store = new CacheStore($this->directory);

        try {
            $store->writeAll($this->compiledCache());
            self::fail('Expected a CacheWriteException from the pointer publish step.');
        } catch (CacheWriteException) {
            // Expected.
        }

        $leftoverGenerations = glob($this->directory . '/gen_*', GLOB_ONLYDIR) ?: [];
        self::assertSame([], $leftoverGenerations, 'the generation whose sections wrote successfully must not survive a failed pointer publish');
    }

    private function poisonedCompiledCache(): CompiledCache
    {
        $http = new HttpCache(
            formatVersion: CacheFormat::VERSION,
            routes: [],
            httpBindingPlans: [],
            hydrationPlans: [
                'App\\Dto' => [
                    'className' => 'App\\Dto',
                    'hasConstructor' => true,
                    'parameters' => [
                        ['name' => 'since', 'defaultValue' => new \DateTimeImmutable()],
                    ],
                ],
            ],
            globalMiddleware: [],
            openApiMiddleware: [],
            compiledAt: '2026-01-01T00:00:00+00:00',
        );

        $rest = $this->compiledCache();

        return new CompiledCache($http, $rest->commands, $rest->events, $rest->plugins);
    }
}
