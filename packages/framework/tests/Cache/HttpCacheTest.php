<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache;

use Kinetis\Cache\CacheFormat;
use Kinetis\Cache\HttpCache;
use PHPUnit\Framework\TestCase;

final class HttpCacheTest extends TestCase
{
    public function test_to_array_from_array_round_trip_preserves_every_field_including_mixed_default_value_types(): void
    {
        $cache = new HttpCache(
            formatVersion: CacheFormat::VERSION,
            routes: [['httpMethod' => 'GET', 'pathTemplate' => '/users', 'controllerClass' => 'App\\C', 'controllerMethod' => 'index', 'status' => 200]],
            httpBindingPlans: [
                'App\\C::index' => [
                    ['name' => 'page', 'source' => 'query', 'dtoClass' => null, 'scalarType' => 'int', 'hasDefault' => true, 'defaultValue' => 1, 'constraints' => []],
                    ['name' => 'flag', 'source' => 'query', 'dtoClass' => null, 'scalarType' => 'bool', 'hasDefault' => true, 'defaultValue' => false, 'constraints' => []],
                    ['name' => 'label', 'source' => 'default', 'dtoClass' => null, 'scalarType' => 'string', 'hasDefault' => true, 'defaultValue' => null, 'constraints' => []],
                ],
            ],
            hydrationPlans: [
                'App\\Dto' => [
                    'className' => 'App\\Dto',
                    'hasConstructor' => true,
                    'parameters' => [
                        ['name' => 'name', 'scalarType' => 'string', 'hasDefault' => false, 'defaultValue' => null, 'constraints' => [
                            ['class' => 'Kinetis\\Validation\\Constraints\\MinLength', 'args' => [3]],
                        ]],
                    ],
                ],
            ],
            globalMiddleware: ['App\\RequestIdMiddleware'],
            mcpMiddleware: ['App\\McpAuthMiddleware'],
            openApiMiddleware: ['App\\OpenApiAuthMiddleware'],
            compiledAt: '2026-01-01T00:00:00+00:00',
            middlewareGroups: ['admin' => ['App\\AuthMiddleware', 'App\\RequireAdminMiddleware']],
        );

        $reconstructed = HttpCache::fromArray($cache->toArray());

        self::assertEquals($cache, $reconstructed);
        self::assertSame(1, $reconstructed->httpBindingPlans['App\\C::index'][0]['defaultValue']);
        self::assertSame(false, $reconstructed->httpBindingPlans['App\\C::index'][1]['defaultValue']);
        self::assertNull($reconstructed->httpBindingPlans['App\\C::index'][2]['defaultValue']);
        self::assertSame(['App\\RequestIdMiddleware'], $reconstructed->globalMiddleware);
        self::assertSame(['App\\McpAuthMiddleware'], $reconstructed->mcpMiddleware);
        self::assertSame(['App\\OpenApiAuthMiddleware'], $reconstructed->openApiMiddleware);
        self::assertSame(
            ['admin' => ['App\\AuthMiddleware', 'App\\RequireAdminMiddleware']],
            $reconstructed->middlewareGroups,
        );
    }

    public function test_var_export_round_trip_via_a_real_generated_file_preserves_shape(): void
    {
        $cache = new HttpCache(
            formatVersion: CacheFormat::VERSION,
            routes: [],
            httpBindingPlans: [],
            hydrationPlans: [],
            globalMiddleware: [],
            mcpMiddleware: [],
            openApiMiddleware: [],
            compiledAt: '2026-01-01T00:00:00+00:00',
        );

        $tmpFile = tempnam(sys_get_temp_dir(), 'kinetis_http_cache_test_') . '.php';
        file_put_contents($tmpFile, '<?php return ' . var_export($cache->toArray(), true) . ';');

        try {
            /** @var array<string, mixed> $data */
            $data = require $tmpFile;
            self::assertEquals($cache, HttpCache::fromArray($data));
        } finally {
            unlink($tmpFile);
        }
    }
}
