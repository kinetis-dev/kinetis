<?php

declare(strict_types=1);

namespace Kinetis\Tests\SimpleCache;

use Kinetis\SimpleCache\Counter;
use Kinetis\Tests\Fixtures\InMemorySimpleCache;
use Kinetis\Tests\Fixtures\NonAtomicCache;
use PHPUnit\Framework\TestCase;

/**
 * Both modes count the same way in sequence. They differ only under
 * concurrent callers, which needs a real backend and separate processes
 * to show — see kinetis/cache-redis's own integration suite.
 */
final class CounterTest extends TestCase
{
    public function test_counts_up_from_nothing(): void
    {
        $counter = new Counter(new InMemorySimpleCache());

        self::assertSame(0, $counter->count('k'));
        self::assertSame(1, $counter->increment('k', 60));
        self::assertSame(2, $counter->increment('k', 60));
        self::assertSame(2, $counter->count('k'));
    }

    public function test_counts_up_from_nothing_without_an_atomic_cache_too(): void
    {
        $counter = new Counter(new NonAtomicCache());

        self::assertSame(0, $counter->count('k'));
        self::assertSame(1, $counter->increment('k', 60));
        self::assertSame(2, $counter->increment('k', 60));
        self::assertSame(2, $counter->count('k'));
    }

    public function test_reports_which_mode_it_is_in(): void
    {
        self::assertTrue(new Counter(new InMemorySimpleCache())->isAtomic());
        self::assertFalse(new Counter(new NonAtomicCache())->isAtomic());
    }

    /**
     * A value that is not a number — a key the application also uses for
     * something else — reads as zero rather than tripping a type error.
     */
    public function test_a_non_numeric_value_counts_as_zero(): void
    {
        $cache = new NonAtomicCache();
        $cache->set('k', ['not', 'a', 'number']);

        self::assertSame(0, new Counter($cache)->count('k'));
    }
}
