<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache;

use Kinetis\Cache\CacheFormat;
use Kinetis\Cache\CommandCache;
use PHPUnit\Framework\TestCase;

final class CommandCacheTest extends TestCase
{
    public function test_to_array_from_array_round_trip_preserves_every_command(): void
    {
        $cache = new CommandCache(
            formatVersion: CacheFormat::VERSION,
            commands: [
                ['name' => 'app:cleanup', 'description' => 'Cleans up', 'controllerClass' => 'App\\C', 'controllerMethod' => 'cleanup', 'takesArguments' => false],
                ['name' => 'app:report', 'description' => '', 'controllerClass' => 'App\\C', 'controllerMethod' => 'report', 'takesArguments' => true],
            ],
            compiledAt: '2026-01-01T00:00:00+00:00',
        );

        self::assertEquals($cache, CommandCache::fromArray($cache->toArray()));
    }
}
