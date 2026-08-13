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
use Kinetis\Cache\McpCache;
use Kinetis\Cache\OpenApiCache;
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
        if (is_dir($this->directory)) {
            foreach (glob($this->directory . '/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($this->directory);
        }
    }

    private function compiledCache(): CompiledCache
    {
        $http = new HttpCache(
            formatVersion: CacheFormat::VERSION,
            routes: [['httpMethod' => 'GET', 'pathTemplate' => '/x', 'controllerClass' => 'App\\C', 'controllerMethod' => 'm', 'status' => 200]],
            httpBindingPlans: [],
            hydrationPlans: [],
            globalMiddleware: [],
            mcpMiddleware: [],
            openApiMiddleware: [],
            compiledAt: '2026-01-01T00:00:00+00:00',
        );
        $mcp = new McpCache(
            formatVersion: CacheFormat::VERSION,
            mcpTools: [],
            mcpResources: [],
            mcpBindingPlans: [],
            hydrationPlans: [],
            compiledAt: '2026-01-01T00:00:00+00:00',
        );
        $openApi = new OpenApiCache(
            formatVersion: CacheFormat::VERSION,
            openApi: ['openapi' => '3.1.0'],
            compiledAt: '2026-01-01T00:00:00+00:00',
        );
        $commands = new CommandCache(
            formatVersion: CacheFormat::VERSION,
            commands: [['name' => 'app:x', 'description' => '', 'controllerClass' => 'App\\C', 'controllerMethod' => 'm', 'takesArguments' => false]],
            compiledAt: '2026-01-01T00:00:00+00:00',
        );
        $events = new EventCache(
            formatVersion: CacheFormat::VERSION,
            listeners: ['App\\SomeEvent' => [['class' => 'App\\SomeListener', 'method' => 'handle', 'priority' => 50]]],
            compiledAt: '2026-01-01T00:00:00+00:00',
        );

        return new CompiledCache($http, $mcp, $openApi, $commands, $events);
    }

    public function test_exists_is_false_when_no_cache_files_exist(): void
    {
        $store = new CacheStore($this->directory);

        self::assertFalse($store->exists());
    }

    public function test_load_methods_return_null_when_no_cache_files_exist(): void
    {
        $store = new CacheStore($this->directory);

        self::assertNull($store->loadHttp());
        self::assertNull($store->loadMcp());
        self::assertNull($store->loadOpenApi());
        self::assertNull($store->loadCommands());
        self::assertNull($store->loadEvents());
    }

    public function test_write_all_then_load_round_trips_each_artifact_independently(): void
    {
        $store = new CacheStore($this->directory);
        $cache = $this->compiledCache();

        $store->writeAll($cache);

        self::assertTrue($store->exists());
        self::assertEquals($cache->http, $store->loadHttp());
        self::assertEquals($cache->mcp, $store->loadMcp());
        self::assertEquals($cache->openApi, $store->loadOpenApi());
        self::assertEquals($cache->commands, $store->loadCommands());
        self::assertEquals($cache->events, $store->loadEvents());
    }

    public function test_a_normal_http_request_never_needs_to_read_mcp_or_openapi_files(): void
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

    public function test_load_returns_null_for_a_mismatched_format_version(): void
    {
        $store = new CacheStore($this->directory);
        mkdir($this->directory, 0775, true);
        file_put_contents($store->httpPath(), "<?php return ['formatVersion' => 999];\n");

        self::assertNull($store->loadHttp());
    }

    public function test_write_all_leaves_no_stray_tmp_files_behind_after_success(): void
    {
        $store = new CacheStore($this->directory);
        $store->writeAll($this->compiledCache());

        $files = glob($this->directory . '/*') ?: [];
        sort($files);

        $expected = [$store->commandsPath(), $store->eventsPath(), $store->httpPath(), $store->mcpPath(), $store->openApiPath()];
        sort($expected);

        self::assertSame($expected, $files);
    }

    public function test_an_object_in_an_artifact_fails_the_write_with_the_path_to_it(): void
    {
        // A constructor default like `new DateTimeImmutable()` captured
        // into a hydration plan is the real way an object reaches a cache
        // artifact — var_export() would render it as a ::__set_state()
        // call the reload can't replay.
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
            mcpMiddleware: [],
            openApiMiddleware: [],
            compiledAt: '2026-01-01T00:00:00+00:00',
        );

        $store = new CacheStore($this->directory);

        try {
            $store->writeAll(new CompiledCache(
                $http,
                $this->compiledCache()->mcp,
                $this->compiledCache()->openApi,
                $this->compiledCache()->commands,
                $this->compiledCache()->events,
            ));
            self::fail('Expected a CacheWriteException.');
        } catch (CacheWriteException $e) {
            self::assertStringContainsString('DateTimeImmutable', $e->getMessage());
            self::assertStringContainsString('defaultValue', $e->getMessage());
        }
    }
}
