<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache;

use Kinetis\Cache\CommandCache;
use Kinetis\Cache\Exception\CacheArtifactExceptionInterface;
use PHPUnit\Framework\TestCase;

final class CommandCacheTest extends TestCase
{
    public function test_to_array_from_array_round_trip_preserves_every_command(): void
    {
        $cache = new CommandCache([
            ['name' => 'app:cleanup', 'description' => 'Cleans up', 'controllerClass' => 'App\\C', 'controllerMethod' => 'cleanup', 'takesArguments' => false, 'bootstrap' => true],
            ['name' => 'app:report', 'description' => '', 'controllerClass' => 'App\\C', 'controllerMethod' => 'report', 'takesArguments' => true, 'bootstrap' => false],
        ]);

        self::assertEquals($cache, CommandCache::fromArray($cache->toArray()));
    }

    public function test_from_array_rejects_an_unexpected_top_level_field(): void
    {
        $cache = new CommandCache([]);

        $this->expectException(CacheArtifactExceptionInterface::class);

        CommandCache::fromArray([...$cache->toArray(), 'extra' => 'nope']);
    }

    public function test_from_array_rejects_a_command_entry_with_an_unexpected_extra_field(): void
    {
        $data = [
            'commands' => [
                ['name' => 'app:x', 'description' => '', 'controllerClass' => 'App\\C', 'controllerMethod' => 'x', 'takesArguments' => false, 'bootstrap' => true, 'extra' => 'nope'],
            ],
        ];

        $this->expectException(CacheArtifactExceptionInterface::class);

        CommandCache::fromArray($data);
    }
}
