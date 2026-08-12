<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache;

use Kinetis\Cache\CacheFormat;
use Kinetis\Cache\McpCache;
use PHPUnit\Framework\TestCase;

final class McpCacheTest extends TestCase
{
    public function test_to_array_from_array_round_trip_preserves_every_field(): void
    {
        $cache = new McpCache(
            formatVersion: CacheFormat::VERSION,
            mcpTools: [['name' => 'a_tool', 'description' => 'd', 'controllerClass' => 'App\\C', 'controllerMethod' => 'm', 'inputSchema' => ['type' => 'object']]],
            mcpResources: [['uri' => 'app://x', 'name' => 'x', 'description' => 'd', 'mimeType' => 'text/plain', 'controllerClass' => 'App\\C', 'controllerMethod' => 'm']],
            mcpBindingPlans: [
                'App\\C::m' => [
                    ['name' => 'progress', 'isProgressReporter' => true, 'dtoClass' => null, 'scalarType' => null, 'hasDefault' => false, 'defaultValue' => null],
                ],
            ],
            hydrationPlans: [],
            compiledAt: '2026-01-01T00:00:00+00:00',
        );

        $reconstructed = McpCache::fromArray($cache->toArray());

        self::assertEquals($cache, $reconstructed);
        self::assertTrue($reconstructed->mcpBindingPlans['App\\C::m'][0]['isProgressReporter']);
    }
}
