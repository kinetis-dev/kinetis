<?php

declare(strict_types=1);

namespace Kinetis\SimpleCache\Tests\Integration;

use Kinetis\Config\Config;
use Kinetis\SimpleCache\RedisSimpleCache;
use PHPUnit\Framework\TestCase;

/**
 * RedisSimpleCache against a real Redis.
 *
 * A mocked "was this command sent" test would prove nothing about a
 * client whose entire job is speaking a wire protocol correctly, which
 * is why this package's cache classes have no unit tests. Running the
 * same checks as a PHPUnit case rather than a standalone script means
 * the coverage they produce is measured, and that a regression here
 * fails the suite rather than only a script somebody remembers to run.
 *
 * Environment-gated: without REDIS_HOST there is nothing to talk to, so
 * these skip rather than fail.
 */
final class RedisSimpleCacheIntegrationTest extends TestCase
{
    private RedisSimpleCache $cache;

    protected function setUp(): void
    {
        $host = \getenv('REDIS_HOST');

        if ($host === false || $host === '') {
            self::markTestSkipped('REDIS_HOST is not set — real-backend cache tests are environment-gated.');
        }

        $cache = RedisSimpleCache::fromConfig(new Config([
            'REDIS_HOST' => $host,
            'REDIS_PORT' => \getenv('REDIS_PORT') ?: '6379',
        ]));

        self::assertNotNull($cache, 'fromConfig() returned null despite REDIS_HOST being set');

        $this->cache = $cache;
        $this->cache->clear();
    }

    public function test_round_trips_a_scalar(): void
    {
        self::assertTrue($this->cache->set('greeting', 'hello'));
        self::assertSame('hello', $this->cache->get('greeting'));
    }

    /**
     * PSR-16 allows any serializable value, so this is the check that the
     * serializer is actually in the path rather than values being cast to
     * strings on the way through.
     */
    public function test_round_trips_a_non_scalar_value(): void
    {
        $this->cache->set('user', ['id' => 1, 'name' => 'Alon']);

        self::assertSame(['id' => 1, 'name' => 'Alon'], $this->cache->get('user'));
    }

    public function test_has_reflects_what_is_stored(): void
    {
        $this->cache->set('greeting', 'hello');

        self::assertTrue($this->cache->has('greeting'));
        self::assertFalse($this->cache->has('missing-key'));
    }

    public function test_delete_removes_the_key(): void
    {
        $this->cache->set('greeting', 'hello');

        self::assertTrue($this->cache->delete('greeting'));
        self::assertFalse($this->cache->has('greeting'));
    }

    public function test_a_missing_key_returns_the_given_default(): void
    {
        self::assertSame('fallback', $this->cache->get('nope', 'fallback'));
    }

    public function test_a_ttl_expires_on_its_own(): void
    {
        $this->cache->set('ttl-key', 'x', 1);
        self::assertTrue($this->cache->has('ttl-key'));

        sleep(2);

        self::assertFalse($this->cache->has('ttl-key'), 'the key outlived its TTL');
    }

    /**
     * Amp\Redis\RedisCache::set() silently no-ops for ttl: 0, which would
     * leave stale data at an already-populated key. RedisSimpleCache
     * deletes instead.
     */
    public function test_a_zero_ttl_deletes_rather_than_writing(): void
    {
        $this->cache->set('zero-ttl', 'first');
        $this->cache->set('zero-ttl', 'second', 0);

        self::assertFalse($this->cache->has('zero-ttl'));
    }

    public function test_multiple_operations_cover_every_key(): void
    {
        self::assertTrue($this->cache->setMultiple(['a' => 1, 'b' => 2, 'c' => 3]));

        $read = iterator_to_array($this->cache->getMultiple(['a', 'b', 'c', 'missing'], 'none'));
        self::assertSame(['a' => 1, 'b' => 2, 'c' => 3, 'missing' => 'none'], $read);

        self::assertTrue($this->cache->deleteMultiple(['a', 'b', 'c']));
        self::assertFalse($this->cache->has('a'));
        self::assertFalse($this->cache->has('b'));
        self::assertFalse($this->cache->has('c'));
    }

    public function test_clear_wipes_everything(): void
    {
        $this->cache->set('will-clear', 'x');

        self::assertTrue($this->cache->clear());
        self::assertFalse($this->cache->has('will-clear'));
    }
}
