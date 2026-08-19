<?php

declare(strict_types=1);

namespace Kinetis\Tests\Security;

use Kinetis\Security\AttemptThrottle;
use Kinetis\Security\Exception\AttemptThrottleUnavailableException;
use Kinetis\SimpleCache\NullSimpleCache;
use Kinetis\Tests\Fixtures\InMemorySimpleCache;
use Kinetis\Tests\Fixtures\NonAtomicCache;
use PHPUnit\Framework\TestCase;

final class AttemptThrottleTest extends TestCase
{
    public function test_an_identifier_with_no_recorded_failures_is_not_throttled(): void
    {
        $throttle = new AttemptThrottle(new InMemorySimpleCache());

        self::assertFalse($throttle->tooManyAttempts('alon@noy.cc'));
    }

    public function test_fires_once_the_max_attempts_threshold_is_reached(): void
    {
        $throttle = new AttemptThrottle(new InMemorySimpleCache(), maxAttempts: 3);

        $throttle->recordFailure('alon@noy.cc');
        $throttle->recordFailure('alon@noy.cc');
        self::assertFalse($throttle->tooManyAttempts('alon@noy.cc'));

        $throttle->recordFailure('alon@noy.cc');
        self::assertTrue($throttle->tooManyAttempts('alon@noy.cc'));
    }

    public function test_clear_resets_an_identifier_immediately(): void
    {
        $throttle = new AttemptThrottle(new InMemorySimpleCache(), maxAttempts: 1);

        $throttle->recordFailure('alon@noy.cc');
        self::assertTrue($throttle->tooManyAttempts('alon@noy.cc'));

        $throttle->clear('alon@noy.cc');
        self::assertFalse($throttle->tooManyAttempts('alon@noy.cc'));
    }

    public function test_two_different_identifiers_do_not_share_a_bucket(): void
    {
        $throttle = new AttemptThrottle(new InMemorySimpleCache(), maxAttempts: 1);

        $throttle->recordFailure('alon@noy.cc');

        self::assertTrue($throttle->tooManyAttempts('alon@noy.cc'));
        self::assertFalse($throttle->tooManyAttempts('someone-else@example.com'));
    }

    public function test_available_in_seconds_is_zero_with_no_active_lockout(): void
    {
        $throttle = new AttemptThrottle(new InMemorySimpleCache());

        self::assertSame(0, $throttle->availableInSeconds('alon@noy.cc'));
    }

    public function test_available_in_seconds_tracks_the_real_decay_window(): void
    {
        $throttle = new AttemptThrottle(new InMemorySimpleCache(), decaySeconds: 60);

        $throttle->recordFailure('alon@noy.cc');

        $remaining = $throttle->availableInSeconds('alon@noy.cc');
        self::assertGreaterThan(55, $remaining);
        self::assertLessThanOrEqual(60, $remaining);
    }

    public function test_the_decay_window_resets_on_every_new_failure(): void
    {
        $throttle = new AttemptThrottle(new InMemorySimpleCache(), maxAttempts: 5, decaySeconds: 60);

        $throttle->recordFailure('alon@noy.cc');
        sleep(1);
        $throttle->recordFailure('alon@noy.cc');

        // A failure one full second after the first still resets the
        // window to a fresh 60 seconds from *this* failure, not the
        // first one — if it hadn't, the remaining time would have
        // drifted below 59.
        self::assertGreaterThanOrEqual(59, $throttle->availableInSeconds('alon@noy.cc'));
    }

    public function test_construction_over_a_null_cache_throws_instead_of_silently_not_enforcing(): void
    {
        $this->expectException(AttemptThrottleUnavailableException::class);

        new AttemptThrottle(new NullSimpleCache());
    }

    /**
     * A cache that cannot count atomically still locks out sequential
     * failures. What it cannot do is count failures arriving together,
     * which is how a password is actually attacked — so the throttle
     * reports which mode it is in rather than leaving it to be assumed.
     */
    public function test_a_cache_that_cannot_count_atomically_is_reported_as_such(): void
    {
        $throttle = new AttemptThrottle(new NonAtomicCache(), maxAttempts: 2, decaySeconds: 60);

        self::assertFalse($throttle->countsAtomically());

        $throttle->recordFailure('ana@example.com');
        self::assertFalse($throttle->tooManyAttempts('ana@example.com'));

        $throttle->recordFailure('ana@example.com');
        self::assertTrue($throttle->tooManyAttempts('ana@example.com'));
    }

    public function test_a_cache_that_can_count_atomically_is_reported_as_such(): void
    {
        self::assertTrue(new AttemptThrottle(new InMemorySimpleCache())->countsAtomically());
    }
}
