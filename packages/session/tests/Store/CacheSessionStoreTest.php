<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests\Store;

use Kinetis\Session\Exception\SessionException;
use Kinetis\Session\Store\CacheSessionStore;
use Kinetis\Session\Tests\Fixtures\InMemorySessionCache;
use Kinetis\SimpleCache\NullSimpleCache;
use PHPUnit\Framework\TestCase;

final class CacheSessionStoreTest extends TestCase
{
    public function test_round_trip_and_destroy(): void
    {
        $store = new CacheSessionStore(new InMemorySessionCache());

        $store->write('a1b2', ['user' => 7], 60);
        self::assertSame(['user' => 7], $store->read('a1b2'));

        $store->destroy('a1b2');
        self::assertNull($store->read('a1b2'));
    }

    public function test_expiry_is_the_backends_ttl(): void
    {
        $cache = new InMemorySessionCache();
        $store = new CacheSessionStore($cache);

        $store->write('a1b2', ['x' => 1], -1);

        self::assertNull($store->read('a1b2'));
    }

    public function test_a_non_array_cached_value_reads_null(): void
    {
        $cache = new InMemorySessionCache();
        $cache->set('session.a1b2', 'scalar garbage');

        self::assertNull(new CacheSessionStore($cache)->read('a1b2'));
    }

    public function test_a_null_cache_is_rejected_at_construction(): void
    {
        $this->expectException(SessionException::class);
        $this->expectExceptionMessage('NullSimpleCache');

        new CacheSessionStore(new NullSimpleCache());
    }
}
