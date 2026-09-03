<?php

declare(strict_types=1);

namespace Kinetis\Tests\Security;

use Kinetis\Security\AttemptThrottle;
use Kinetis\Security\Exception\AttemptThrottleUnavailableException;
use Kinetis\Security\Exception\InvalidAttemptThrottleConfigException;
use Kinetis\SimpleCache\NullSimpleCache;
use Kinetis\Tests\Fixtures\InMemorySimpleCache;
use Kinetis\Tests\Fixtures\NonAtomicCache;
use PHPUnit\Framework\TestCase;

final class AttemptThrottleTest extends TestCase
{
    public function test_an_identifier_with_no_recorded_failures_is_not_throttled(): void
    {
        $throttle = new AttemptThrottle(new InMemorySimpleCache());

        self::assertFalse($throttle->tooManyAttempts('alon@example.com'));
    }

    public function test_fires_once_the_max_attempts_threshold_is_reached(): void
    {
        $throttle = new AttemptThrottle(new InMemorySimpleCache(), maxAttempts: 3);

        $throttle->recordFailure('alon@example.com');
        $throttle->recordFailure('alon@example.com');
        self::assertFalse($throttle->tooManyAttempts('alon@example.com'));

        $throttle->recordFailure('alon@example.com');
        self::assertTrue($throttle->tooManyAttempts('alon@example.com'));
    }

    public function test_clear_resets_an_identifier_immediately(): void
    {
        $throttle = new AttemptThrottle(new InMemorySimpleCache(), maxAttempts: 1);

        $throttle->recordFailure('alon@example.com');
        self::assertTrue($throttle->tooManyAttempts('alon@example.com'));

        $throttle->clear('alon@example.com');
        self::assertFalse($throttle->tooManyAttempts('alon@example.com'));
    }

    public function test_two_different_identifiers_do_not_share_a_bucket(): void
    {
        $throttle = new AttemptThrottle(new InMemorySimpleCache(), maxAttempts: 1);

        $throttle->recordFailure('alon@example.com');

        self::assertTrue($throttle->tooManyAttempts('alon@example.com'));
        self::assertFalse($throttle->tooManyAttempts('someone-else@example.com'));
    }

    public function test_available_in_seconds_is_zero_with_no_active_lockout(): void
    {
        $throttle = new AttemptThrottle(new InMemorySimpleCache());

        self::assertSame(0, $throttle->availableInSeconds('alon@example.com'));
    }

    public function test_available_in_seconds_tracks_the_real_decay_window(): void
    {
        $throttle = new AttemptThrottle(new InMemorySimpleCache(), decaySeconds: 60);

        $throttle->recordFailure('alon@example.com');

        $remaining = $throttle->availableInSeconds('alon@example.com');
        self::assertGreaterThan(55, $remaining);
        self::assertLessThanOrEqual(60, $remaining);
    }

    public function test_the_decay_window_resets_on_every_new_failure(): void
    {
        $throttle = new AttemptThrottle(new InMemorySimpleCache(), maxAttempts: 5, decaySeconds: 60);

        $throttle->recordFailure('alon@example.com');
        sleep(1);
        $throttle->recordFailure('alon@example.com');

        // A failure one full second after the first still resets the
        // window to a fresh 60 seconds from *this* failure, not the
        // first one — if it hadn't, the remaining time would have
        // drifted below 59.
        self::assertGreaterThanOrEqual(59, $throttle->availableInSeconds('alon@example.com'));
    }

    public function test_construction_over_a_null_cache_throws_instead_of_silently_not_enforcing(): void
    {
        $this->expectException(AttemptThrottleUnavailableException::class);

        new AttemptThrottle(new NullSimpleCache());
    }

    public function test_construction_over_a_cache_that_cannot_count_atomically_throws(): void
    {
        $this->expectException(AttemptThrottleUnavailableException::class);
        $this->expectExceptionMessage('AtomicCounterInterface');

        new AttemptThrottle(new NonAtomicCache());
    }

    public function test_construction_over_a_cache_that_can_count_atomically_succeeds(): void
    {
        $throttle = new AttemptThrottle(new InMemorySimpleCache(), maxAttempts: 2, decaySeconds: 60);

        $throttle->recordFailure('ana@example.com');
        self::assertFalse($throttle->tooManyAttempts('ana@example.com'));

        $throttle->recordFailure('ana@example.com');
        self::assertTrue($throttle->tooManyAttempts('ana@example.com'));
    }

    /**
     * A plain delimited join of namespace/maxAttempts/decaySeconds/
     * identifier has no safe delimiter: namespace "a", maxAttempts 5,
     * decaySeconds 900, identifier "7:x" joins to the exact same string
     * as namespace "a:5", maxAttempts 900, decaySeconds 7, identifier
     * "x" — two genuinely different policies that must never share a
     * bucket just because their fields happen to concatenate the same.
     */
    public function test_a_naive_delimited_join_of_config_and_identifier_does_not_collide(): void
    {
        $cache = new InMemorySimpleCache();
        $first = new AttemptThrottle($cache, maxAttempts: 5, decaySeconds: 900, namespace: 'a');
        $second = new AttemptThrottle($cache, maxAttempts: 900, decaySeconds: 7, namespace: 'a:5');

        for ($i = 0; $i < 5; $i++) {
            $first->recordFailure('7:x');
        }

        self::assertTrue($first->tooManyAttempts('7:x'));

        // Proven via clear(), not tooManyAttempts()'s own numeric
        // threshold: $second's maxAttempts (900) is deliberately
        // unreachable in this test, so a threshold comparison alone
        // can't tell a real key collision apart from none. Clearing
        // $second's own, differently-shaped identifier must never
        // erase $first's real lockout if the two policies are
        // genuinely separate buckets — a naive delimited join of
        // (namespace, maxAttempts, decaySeconds, identifier) collides
        // here: namespace "a", maxAttempts 5, decaySeconds 900,
        // identifier "7:x" joins to the exact same string as namespace
        // "a:5", maxAttempts 900, decaySeconds 7, identifier "x".
        $second->clear('x');

        self::assertTrue($first->tooManyAttempts('7:x'));
    }

    public function test_two_purposes_sharing_one_cache_and_identifier_have_independent_record_and_clear_via_namespace(): void
    {
        $cache = new InMemorySimpleCache();
        $login = new AttemptThrottle($cache, maxAttempts: 1, namespace: 'login');
        $twoFactor = new AttemptThrottle($cache, maxAttempts: 1, namespace: '2fa');

        $login->recordFailure('alon@example.com');

        self::assertTrue($login->tooManyAttempts('alon@example.com'));
        self::assertFalse($twoFactor->tooManyAttempts('alon@example.com'));

        $twoFactor->recordFailure('alon@example.com');
        self::assertTrue($twoFactor->tooManyAttempts('alon@example.com'));

        // Clearing one purpose's lockout must never clear the other's.
        $login->clear('alon@example.com');
        self::assertFalse($login->tooManyAttempts('alon@example.com'));
        self::assertTrue($twoFactor->tooManyAttempts('alon@example.com'));
    }

    public function test_two_purposes_with_identical_configuration_have_independent_expiry_via_namespace(): void
    {
        $cache = new InMemorySimpleCache();
        $login = new AttemptThrottle($cache, decaySeconds: 60, namespace: 'login');
        $twoFactor = new AttemptThrottle($cache, decaySeconds: 60, namespace: '2fa');

        $login->recordFailure('alon@example.com');

        self::assertGreaterThan(0, $login->availableInSeconds('alon@example.com'));
        self::assertSame(0, $twoFactor->availableInSeconds('alon@example.com'));
    }

    public function test_two_default_namespaced_instances_with_different_configuration_have_independent_buckets(): void
    {
        $cache = new InMemorySimpleCache();
        $strict = new AttemptThrottle($cache, maxAttempts: 1, decaySeconds: 900);
        $lenient = new AttemptThrottle($cache, maxAttempts: 5, decaySeconds: 60);

        $strict->recordFailure('alon@example.com');

        self::assertTrue($strict->tooManyAttempts('alon@example.com'));

        // Proven via clear(), not tooManyAttempts()'s own numeric
        // threshold — $lenient's own count could coincidentally read as
        // "not too many" (5) whether or not it shares $strict's bucket,
        // so a threshold comparison alone can't tell them apart.
        // Clearing $lenient's own bucket for the identical identifier
        // must never affect $strict's real lockout if the two
        // configurations are genuinely separate.
        $lenient->clear('alon@example.com');

        self::assertTrue($strict->tooManyAttempts('alon@example.com'));
    }

    /**
     * Every real cache key this class ever writes must be pure hex (the
     * output of hash('sha256', ...)) — never the raw namespace or
     * identifier text, and never a PSR-16-reserved character
     * ({}()/\@:), regardless of what a caller passes as either.
     */
    public function test_every_stored_key_is_pure_hex_and_never_leaks_the_raw_namespace_or_identifier(): void
    {
        $cache = new InMemorySimpleCache();
        $throttle = new AttemptThrottle($cache, namespace: 'weird{}()/\@: namespace');

        $throttle->recordFailure('weird{}()/\@: identifier@example.com');

        $keys = $cache->keys();

        self::assertNotEmpty($keys);

        foreach ($keys as $key) {
            self::assertMatchesRegularExpression(
                '/^attempt-throttle(-expiry)?\.[0-9a-f]{64}$/',
                $key,
                "key \"{$key}\" is not pure hex — it may leak raw caller-supplied text",
            );
        }
    }

    public function test_construction_rejects_a_non_positive_max_attempts(): void
    {
        $this->expectException(InvalidAttemptThrottleConfigException::class);
        $this->expectExceptionMessage('maxAttempts of at least 1, got 0');

        new AttemptThrottle(new InMemorySimpleCache(), maxAttempts: 0);
    }

    public function test_construction_rejects_a_non_positive_decay(): void
    {
        $this->expectException(InvalidAttemptThrottleConfigException::class);
        $this->expectExceptionMessage('decaySeconds of at least 1, got -1');

        new AttemptThrottle(new InMemorySimpleCache(), decaySeconds: -1);
    }
}
