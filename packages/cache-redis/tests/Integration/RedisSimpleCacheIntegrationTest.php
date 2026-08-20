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

    public function test_increment_counts_up_from_nothing_and_sets_the_expiry(): void
    {
        $key = 'counter-' . \bin2hex(\random_bytes(6));

        self::assertSame(0, $this->cache->count($key), 'a counter that does not exist reads as zero');
        self::assertSame(1, $this->cache->increment($key, 60));
        self::assertSame(2, $this->cache->increment($key, 60));
        self::assertSame(2, $this->cache->count($key));
    }

    /**
     * The expiry is refreshed on every increment, not just when the
     * counter is created — what lets a caller decay a count from the
     * last increment rather than the first.
     */
    public function test_increment_expires_the_counter(): void
    {
        $key = 'counter-ttl-' . \bin2hex(\random_bytes(6));

        $this->cache->increment($key, 1);
        self::assertSame(1, $this->cache->count($key));

        \sleep(2);

        self::assertSame(0, $this->cache->count($key), 'the counter should have expired on its own');
    }

    /**
     * The property the whole interface exists for. Read-then-write lets
     * concurrent callers receive the same value; these must every one be
     * distinct, which is what stops a limit admitting everything that
     * arrives together.
     *
     * Fibers rather than processes: they interleave at every suspension
     * point the client has, which is where a read-then-write loses an
     * increment, and they need no separate runner.
     */
    public function test_concurrent_increments_each_receive_a_distinct_value(): void
    {
        $key = 'counter-race-' . \bin2hex(\random_bytes(6));
        $tasks = [];

        for ($i = 0; $i < 25; $i++) {
            $tasks[] = fn (): int => $this->cache->increment($key, 60);
        }

        $values = \Kinetis\Async\concurrently($tasks);

        \sort($values);
        self::assertSame(\range(1, 25), $values, 'every caller must receive its own value');
        self::assertSame(25, $this->cache->count($key));
    }

    public function test_consume_returns_the_value_and_deletes_the_key(): void
    {
        $key = 'consume-' . \bin2hex(\random_bytes(6));
        $this->cache->set($key, ['claim' => 'once']);

        self::assertSame(['claim' => 'once'], $this->cache->consume($key));
        self::assertFalse($this->cache->has($key));
    }

    public function test_consume_on_a_missing_key_returns_the_given_default(): void
    {
        self::assertSame('fallback', $this->cache->consume('nope', 'fallback'));
    }

    /**
     * The property AtomicConsumeInterface exists for, mirroring
     * test_concurrent_increments_each_receive_a_distinct_value() above: a
     * get() then a separate delete() lets concurrent callers both read
     * the value before either deletes it, so this must never happen —
     * exactly one of N concurrent consumers may receive the real value.
     */
    public function test_concurrent_consumes_of_the_same_key_only_one_receives_the_value(): void
    {
        $key = 'consume-race-' . \bin2hex(\random_bytes(6));
        $this->cache->set($key, 'the-only-copy');

        $tasks = [];

        for ($i = 0; $i < 25; $i++) {
            $tasks[] = fn (): mixed => $this->cache->consume($key, 'missed-it');
        }

        $values = \Kinetis\Async\concurrently($tasks);

        self::assertSame(1, \count(\array_filter($values, static fn ($v) => $v === 'the-only-copy')), 'exactly one caller must receive the value');
        self::assertSame(24, \count(\array_filter($values, static fn ($v) => $v === 'missed-it')), 'every other caller must receive the default');
        self::assertFalse($this->cache->has($key));
    }
}
