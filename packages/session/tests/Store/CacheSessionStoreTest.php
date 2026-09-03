<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests\Store;

use Kinetis\Session\Exception\SessionException;
use Kinetis\Session\Store\CacheSessionStore;
use Kinetis\Session\Tests\Fixtures\FailingWriteCache;
use Kinetis\Session\Tests\Fixtures\InMemorySessionCache;
use Kinetis\SimpleCache\NullSimpleCache;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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

    /**
     * CacheSessionStore itself computes no expiry at all — the backend
     * does, from whatever TTL it's given. Seeded directly on the cache
     * (bypassing write(), which now rejects a non-positive lifetime —
     * KINETIS-68) with a TTL already in the past, using the same
     * "session." key prefix write()'s own private keyFor() applies —
     * so read() must observe the backend's own expiry, not anything
     * this store tracks itself.
     */
    public function test_expiry_is_the_backends_ttl(): void
    {
        $cache = new InMemorySessionCache();
        $store = new CacheSessionStore($cache);

        $cache->set('session.a1b2', ['x' => 1], -1);

        self::assertNull($store->read('a1b2'));
    }

    /**
     * KINETIS-68: aligned with the other two stores as far as this store
     * actually controls — it never computes an absolute timestamp
     * itself, so there's no overflow arithmetic to guard here, but a
     * non-positive lifetime is still rejected before the cache backend
     * is ever touched, the same as File/SqlSessionStore.
     */
    public function test_write_rejects_a_non_positive_lifetime_before_touching_the_cache(): void
    {
        $cache = new InMemorySessionCache();
        $store = new CacheSessionStore($cache);

        foreach ([0, -1] as $lifetime) {
            try {
                $store->write('a1b2', ['x' => 1], $lifetime);
                self::fail("Expected SessionException for lifetime {$lifetime}.");
            } catch (SessionException $e) {
                self::assertStringContainsString('Session lifetime must be a positive number of seconds', $e->getMessage());
            }
        }

        self::assertNull($store->read('a1b2'), 'a rejected lifetime must never reach the cache.');
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

    public function test_write_throws_when_the_cache_reports_a_false_set(): void
    {
        $cache = new FailingWriteCache();
        $cache->failSet = true;
        $store = new CacheSessionStore($cache);

        $this->expectException(SessionException::class);
        $this->expectExceptionMessage('Session data for "a1b2" could not be written to the cache.');

        $store->write('a1b2', ['user' => 7], 60);
    }

    public function test_destroy_throws_when_the_cache_reports_a_false_delete(): void
    {
        $cache = new FailingWriteCache();
        $cache->failDelete = true;
        $store = new CacheSessionStore($cache);

        $this->expectException(SessionException::class);
        $this->expectExceptionMessage('Session data for "a1b2" could not be deleted from the cache.');

        $store->destroy('a1b2');
    }

    public function test_a_cache_that_throws_on_set_propagates_its_own_exception_unwrapped(): void
    {
        $cache = new FailingWriteCache();
        $cache->throwOnSet = new RuntimeException('connection reset');
        $store = new CacheSessionStore($cache);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('connection reset');

        $store->write('a1b2', ['user' => 7], 60);
    }

    public function test_a_cache_that_throws_on_delete_propagates_its_own_exception_unwrapped(): void
    {
        $cache = new FailingWriteCache();
        $cache->throwOnDelete = new RuntimeException('connection reset');
        $store = new CacheSessionStore($cache);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('connection reset');

        $store->destroy('a1b2');
    }
}
