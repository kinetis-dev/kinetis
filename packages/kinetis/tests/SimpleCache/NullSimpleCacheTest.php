<?php

declare(strict_types=1);

namespace Kinetis\Tests\SimpleCache;

use Kinetis\SimpleCache\NullSimpleCache;
use PHPUnit\Framework\TestCase;

final class NullSimpleCacheTest extends TestCase
{
    public function test_get_always_misses_and_returns_the_default(): void
    {
        $cache = new NullSimpleCache();
        $cache->set('key', 'value');

        self::assertSame('fallback', $cache->get('key', 'fallback'));
        self::assertNull($cache->get('key'));
    }

    public function test_has_is_always_false(): void
    {
        $cache = new NullSimpleCache();
        $cache->set('key', 'value');

        self::assertFalse($cache->has('key'));
    }

    public function test_set_delete_and_clear_report_success_without_storing_anything(): void
    {
        $cache = new NullSimpleCache();

        self::assertTrue($cache->set('key', 'value'));
        self::assertTrue($cache->delete('key'));
        self::assertTrue($cache->clear());
    }

    public function test_get_multiple_returns_the_default_for_every_key(): void
    {
        $cache = new NullSimpleCache();

        self::assertSame(
            ['a' => 'fallback', 'b' => 'fallback'],
            $cache->getMultiple(['a', 'b'], 'fallback'),
        );
    }

    public function test_set_multiple_and_delete_multiple_report_success(): void
    {
        $cache = new NullSimpleCache();

        self::assertTrue($cache->setMultiple(['a' => 1, 'b' => 2]));
        self::assertTrue($cache->deleteMultiple(['a', 'b']));
    }
}
