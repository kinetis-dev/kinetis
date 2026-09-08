<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests\Integration;

use Kinetis\Config\Config;
use Kinetis\Session\Store\RedisSessionStore;
use Kinetis\SimpleCache\RedisSimpleCache;
use PHPUnit\Framework\TestCase;

/**
 * RedisSessionStore against a real Redis. The conditional `SET ... XX`
 * update is a server-side decision, so a fake would only restate this
 * store's own expectation of it.
 *
 * Environment-gated on REDIS_HOST, like every other real-backend test in
 * this repository.
 */
final class RedisSessionStoreIntegrationTest extends TestCase
{
    private RedisSessionStore $store;

    private RedisSimpleCache $cache;

    protected function setUp(): void
    {
        $host = \getenv('REDIS_HOST');

        if ($host === false || $host === '') {
            self::markTestSkipped('REDIS_HOST is not set — real-backend session tests are environment-gated.');
        }

        $cache = RedisSimpleCache::fromConfig(new Config([
            'REDIS_HOST' => $host,
            'REDIS_PORT' => \getenv('REDIS_PORT') ?: '6379',
            'REDIS_CACHE_NAMESPACE' => 'session-tests',
        ]));

        self::assertNotNull($cache, 'fromConfig() returned null despite REDIS_HOST being set');

        $cache->clear();
        $this->cache = $cache;
        $this->store = new RedisSessionStore($cache);
    }

    public function test_a_created_session_round_trips_through_json(): void
    {
        $this->store->create('sid-1', ['user' => 42, 'nested' => ['a' => true]], 60);

        self::assertSame(['user' => 42, 'nested' => ['a' => true]], $this->store->read('sid-1'));
        self::assertIsString($this->cache->get('session.sid-1'), 'the stored value is JSON text, not a PHP value graph.');
    }

    public function test_an_update_replaces_a_live_record(): void
    {
        $this->store->create('sid-2', ['step' => 1], 60);

        self::assertTrue($this->store->update('sid-2', ['step' => 2], 60));
        self::assertSame(['step' => 2], $this->store->read('sid-2'));
    }

    public function test_an_update_after_the_record_was_destroyed_is_refused(): void
    {
        $this->store->create('sid-3', ['user' => 42], 60);
        $this->store->destroy('sid-3');

        self::assertFalse($this->store->update('sid-3', ['user' => 42], 60));
        self::assertNull($this->store->read('sid-3'), 'a refused update must not recreate the key.');
    }
}
