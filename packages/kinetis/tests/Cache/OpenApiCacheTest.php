<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache;

use Kinetis\Cache\CacheFormat;
use Kinetis\Cache\OpenApiCache;
use PHPUnit\Framework\TestCase;

final class OpenApiCacheTest extends TestCase
{
    public function test_to_array_from_array_round_trip_preserves_the_document(): void
    {
        $cache = new OpenApiCache(
            formatVersion: CacheFormat::VERSION,
            openApi: ['openapi' => '3.1.0', 'paths' => ['/users' => ['get' => []]]],
            compiledAt: '2026-01-01T00:00:00+00:00',
        );

        self::assertEquals($cache, OpenApiCache::fromArray($cache->toArray()));
    }
}
