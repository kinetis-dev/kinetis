<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache;

use Kinetis\Cache\CacheFormat;
use Kinetis\Cache\CacheStore;
use Kinetis\Cache\CommandCache;
use Kinetis\Cache\CompiledCache;
use Kinetis\Cache\EventCache;
use Kinetis\Cache\Exception\CacheWriteException;
use Kinetis\Cache\Exception\InvalidCacheArtifactException;
use Kinetis\Cache\Exception\UnexportableArtifactException;
use Kinetis\Cache\HttpCache;
use Kinetis\Cache\PluginCache;
use Kinetis\Tests\Validation\Fixtures\EachRulesRequest;
use Kinetis\Tests\Validation\Fixtures\EnumDefaultRequest;
use Kinetis\Tests\Validation\Fixtures\SortDirection;
use Kinetis\Validation\Hydrator;
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
        foreach (glob($this->directory . '/*') ?: [] as $entry) {
            is_dir($entry) ? @rmdir($entry) : @unlink($entry);
        }

        @rmdir($this->directory);
    }

    /**
     * Every one of the four sections, and the artifact's own package-
     * bootstrap list, carries something distinguishable per $marker, so
     * a read that returned one publish's HttpCache alongside another's
     * CommandCache would show up here rather than passing silently.
     */
    private function compiledCache(string $marker = 'a'): CompiledCache
    {
        $http = new HttpCache(
            routes: [['httpMethod' => 'GET', 'pathTemplate' => "/{$marker}", 'controllerClass' => "App\\C{$marker}", 'controllerMethod' => 'm', 'status' => 200, 'middleware' => []]],
            httpBindingPlans: [],
            hydrationPlans: [],
            globalMiddleware: [],
            openApiMiddleware: [],
        );
        $commands = new CommandCache([
            ['name' => "app:{$marker}", 'description' => '', 'controllerClass' => "App\\C{$marker}", 'controllerMethod' => 'm', 'takesArguments' => false, 'bootstrap' => true],
        ]);
        $events = new EventCache([
            "App\\SomeEvent{$marker}" => [['class' => "App\\SomeListener{$marker}", 'method' => 'handle', 'priority' => 50, 'queued' => true]],
        ]);
        $plugins = new PluginCache(["App\\SomeRegistry{$marker}" => ['x' => 1]]);

        return new CompiledCache($http, $commands, $events, $plugins, ["App\\Package{$marker}Bootstrap"]);
    }

    public function test_load_returns_null_when_nothing_has_been_published(): void
    {
        self::assertNull((new CacheStore($this->directory))->load());
    }

    public function test_write_then_load_returns_every_section(): void
    {
        $cache = $this->compiledCache();

        (new CacheStore($this->directory))->write($cache);
        $loaded = (new CacheStore($this->directory))->load();

        self::assertNotNull($loaded);
        self::assertEquals($cache->http, $loaded->http);
        self::assertEquals($cache->commands, $loaded->commands);
        self::assertEquals($cache->events, $loaded->events);
        self::assertEquals($cache->plugins, $loaded->plugins);
        self::assertSame($cache->packageBootstraps, $loaded->packageBootstraps);
    }

    public function test_write_creates_the_cache_directory(): void
    {
        self::assertDirectoryDoesNotExist($this->directory);

        (new CacheStore($this->directory))->write($this->compiledCache());

        self::assertFileExists($this->directory . '/' . CacheStore::ARTIFACT_FILENAME);
    }

    public function test_path_names_the_one_artifact_every_boot_reads(): void
    {
        self::assertSame(
            $this->directory . '/compiled.php',
            (new CacheStore($this->directory))->path(),
        );
    }

    /**
     * The whole artifact is replaced, not merged into: a second publish
     * carrying different data in every section leaves nothing of the
     * first behind.
     */
    public function test_a_second_write_replaces_the_whole_artifact(): void
    {
        (new CacheStore($this->directory))->write($this->compiledCache('a'));
        (new CacheStore($this->directory))->write($this->compiledCache('b'));

        $loaded = (new CacheStore($this->directory))->load();

        self::assertNotNull($loaded);
        self::assertEquals($this->compiledCache('b')->http, $loaded->http);
        self::assertEquals($this->compiledCache('b')->commands, $loaded->commands);
        self::assertEquals($this->compiledCache('b')->events, $loaded->events);
        self::assertEquals($this->compiledCache('b')->plugins, $loaded->plugins);
        self::assertSame($this->compiledCache('b')->packageBootstraps, $loaded->packageBootstraps);
    }

    /**
     * A publish leaves no temporary file behind, successful or not — the
     * staged file is renamed onto the real path on success and unlinked
     * on failure, so the directory only ever holds the artifact itself.
     */
    public function test_a_successful_publish_leaves_no_staged_file(): void
    {
        (new CacheStore($this->directory))->write($this->compiledCache());

        self::assertSame([], glob($this->directory . '/*.tmp') ?: []);
    }

    /**
     * A file whose PHP will not parse — a truncated write, disk
     * corruption, tampering — is as unusable as no file at all, and is
     * reported the same way rather than escaping as a ParseError.
     */
    public function test_load_returns_null_for_an_unparseable_artifact(): void
    {
        $store = new CacheStore($this->directory);
        $store->write($this->compiledCache());
        file_put_contents($store->path(), "<?php\n\nreturn [ this is not php");

        self::assertNull((new CacheStore($this->directory))->load());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableArtifactBodies(): iterable
    {
        yield 'not an array' => ['<?php return "nope";'];
        yield 'no format version' => ['<?php return ["http" => []];'];
        yield 'a different format version' => ['<?php return ["formatVersion" => ' . (CacheFormat::VERSION + 1) . '];'];
        yield 'a string format version' => ['<?php return ["formatVersion" => "' . CacheFormat::VERSION . '"];'];
        // The format hydration plans carried before they recorded
        // DTO-level rules and presence unions. Its parameters and plan
        // roots are missing fields this build reads, so it must never be
        // reconstructed — only recompiled.
        yield 'the previous format version' => ['<?php return ["formatVersion" => 20];'];
    }

    /**
     * Anything the running build cannot speak reads as absent, so the
     * boot that finds it recompiles instead of reconstructing from data
     * whose shape it no longer agrees with. The string-versus-integer
     * case is the reason the comparison is strict.
     */
    #[DataProvider('unusableArtifactBodies')]
    public function test_load_returns_null_for_an_unusable_artifact(string $body): void
    {
        mkdir($this->directory, 0775, true);
        file_put_contents($this->directory . '/' . CacheStore::ARTIFACT_FILENAME, $body);

        self::assertNull((new CacheStore($this->directory))->load());
    }

    /**
     * A file this build *does* speak the version of, but whose sections
     * are malformed, is a different question from an unreadable one:
     * `load()` lets the section's own refusal out, and BootSequence is
     * what classifies it into a recompile.
     */
    public function test_load_propagates_a_malformed_section(): void
    {
        mkdir($this->directory, 0775, true);
        file_put_contents(
            $this->directory . '/' . CacheStore::ARTIFACT_FILENAME,
            '<?php return ["formatVersion" => ' . CacheFormat::VERSION
                . ', "packageBootstraps" => [], "http" => "not an array", "commands" => [], "events" => [], "plugins" => []];',
        );

        $this->expectException(InvalidCacheArtifactException::class);

        (new CacheStore($this->directory))->load();
    }

    /**
     * A live object anywhere in the artifact is a defect in what was
     * compiled, not a failure to persist it — var_export() renders it as
     * a `::__set_state()` call most classes cannot replay. Refused by its
     * own exception type, so the runtime's compile-in-memory fallback,
     * which continues past a persistence failure, does not continue past
     * this one.
     */
    public function test_write_refuses_an_artifact_carrying_a_live_object(): void
    {
        $this->expectException(UnexportableArtifactException::class);

        (new CacheStore($this->directory))->write($this->poisonedCompiledCache());
    }

    public function test_a_refused_artifact_leaves_the_previous_one_intact(): void
    {
        $store = new CacheStore($this->directory);
        $store->write($this->compiledCache('a'));

        try {
            $store->write($this->poisonedCompiledCache());
            self::fail('Expected an UnexportableArtifactException.');
        } catch (UnexportableArtifactException) {
            // Expected.
        }

        $loaded = (new CacheStore($this->directory))->load();

        self::assertNotNull($loaded);
        self::assertEquals($this->compiledCache('a')->http, $loaded->http);
        self::assertSame([], glob($this->directory . '/*.tmp') ?: []);
    }

    /**
     * A directory sitting where the artifact belongs makes rename() fail
     * with no permission games — the one portable way to exercise the
     * publish step's own failure path.
     */
    public function test_a_failed_publish_reports_itself_and_leaves_no_staged_file(): void
    {
        mkdir($this->directory . '/' . CacheStore::ARTIFACT_FILENAME, 0775, true);

        try {
            (new CacheStore($this->directory))->write($this->compiledCache());
            self::fail('Expected a CacheWriteException from the publish step.');
        } catch (CacheWriteException) {
            // Expected.
        }

        self::assertSame([], glob($this->directory . '/*.tmp') ?: []);
    }

    public function test_write_reports_a_cache_directory_it_cannot_create(): void
    {
        $blocker = sys_get_temp_dir() . '/kinetis_cache_store_blocker_' . bin2hex(random_bytes(8));
        file_put_contents($blocker, 'not a directory');

        try {
            $this->expectException(CacheWriteException::class);

            (new CacheStore($blocker . '/nested'))->write($this->compiledCache());
        } finally {
            unlink($blocker);
        }
    }


    /**
     * The one object an artifact carries: `var_export()` writes an enum
     * case as the literal `\Kinetis\...\SortDirection::Ascending`, which
     * a reload evaluates back to the very same case — enum cases are
     * process-wide singletons, so write()'s own reconstruction check,
     * which compares the required-back array against the one it
     * rendered, passes on identity.
     */
    public function test_an_enum_case_default_survives_the_artifact_round_trip(): void
    {
        $store = new CacheStore($this->directory);
        $store->write($this->cacheCarrying(Hydrator::compilePlan(EnumDefaultRequest::class)));

        $reloaded = $store->load()?->http->hydrationPlans[EnumDefaultRequest::class];

        self::assertNotNull($reloaded);
        self::assertSame(SortDirection::Ascending, $reloaded['parameters'][1]['defaultValue']);
        self::assertStringContainsString(
            SortDirection::class . '::Ascending',
            (string) file_get_contents($store->path()),
        );
    }

    /**
     * A typed collection's own plan through the real artifact: written
     * by var_export(), required back, and validated by the loader that
     * every production boot goes through. The item descriptor is plain
     * data — class names, type names and literal rule arguments — so
     * there is nothing in it an artifact could not carry.
     */
    public function test_a_typed_collection_plan_survives_the_artifact_round_trip(): void
    {
        $store = new CacheStore($this->directory);
        $store->write($this->cacheCarrying(Hydrator::compilePlan(EachRulesRequest::class)));

        $reloaded = $store->load()?->http->hydrationPlans[EachRulesRequest::class];

        self::assertNotNull($reloaded);
        self::assertSame(
            Hydrator::compilePlan(EachRulesRequest::class),
            $reloaded,
        );
        self::assertSame(
            ['AB', 'CD'],
            Hydrator::hydrate(EachRulesRequest::class, ['codes' => ['AB', 'CD']], $reloaded)->codes,
        );
    }

    /**
     * Why every other object stays refused, shown against the format
     * itself rather than against the check: `var_export()` renders one as
     * a `::__set_state()` call, and requiring that back is a fatal Error
     * for a class that does not implement the method. A default
     * constructing such an object is rejected earlier still, where
     * Kinetis\Reflection\ParameterDefault derives the plan.
     */
    public function test_a_non_enum_object_has_no_var_export_round_trip(): void
    {
        mkdir($this->directory, 0775, true);
        $path = $this->directory . '/probe.php';
        file_put_contents($path, "<?php\n\nreturn " . var_export(['defaultValue' => new \ArrayObject()], true) . ";\n");

        $this->expectException(\Error::class);

        require $path;
    }

    private function cacheCarrying(array $hydrationPlan): CompiledCache
    {
        $rest = $this->compiledCache();
        $http = new HttpCache(
            routes: [],
            httpBindingPlans: [],
            hydrationPlans: [$hydrationPlan['className'] => $hydrationPlan],
            globalMiddleware: [],
            openApiMiddleware: [],
        );

        return new CompiledCache($http, $rest->commands, $rest->events, $rest->plugins, $rest->packageBootstraps);
    }

    private function poisonedCompiledCache(): CompiledCache
    {
        $http = new HttpCache(
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
        );

        $rest = $this->compiledCache();

        return new CompiledCache($http, $rest->commands, $rest->events, $rest->plugins, $rest->packageBootstraps);
    }
}
