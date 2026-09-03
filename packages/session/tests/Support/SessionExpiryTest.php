<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests\Support;

use Kinetis\Session\Exception\SessionException;
use Kinetis\Session\Support\SessionExpiry;
use PHPUnit\Framework\TestCase;

final class SessionExpiryTest extends TestCase
{
    public function test_assert_valid_lifetime_accepts_one(): void
    {
        SessionExpiry::assertValidLifetime(1);

        self::assertTrue(true, 'assertValidLifetime(1) must not throw.');
    }

    public function test_assert_valid_lifetime_rejects_zero(): void
    {
        $this->expectException(SessionException::class);
        $this->expectExceptionMessage('Session lifetime must be a positive number of seconds, got 0.');

        SessionExpiry::assertValidLifetime(0);
    }

    public function test_assert_valid_lifetime_rejects_a_negative_value(): void
    {
        $this->expectException(SessionException::class);
        $this->expectExceptionMessage('Session lifetime must be a positive number of seconds, got -1.');

        SessionExpiry::assertValidLifetime(-1);
    }

    /**
     * A tolerant bound rather than an exact equality: time() can tick
     * over by a second between this test's own call and the one inside
     * timestampFor() — genuinely rare, but a flake this deterministic
     * test must not have any chance of producing.
     */
    public function test_timestamp_for_is_now_plus_the_lifetime(): void
    {
        $before = \time();
        $timestamp = SessionExpiry::timestampFor(3600);
        $after = \time();

        self::assertGreaterThanOrEqual($before + 3600, $timestamp);
        self::assertLessThanOrEqual($after + 3600, $timestamp);
    }

    public function test_timestamp_for_rejects_a_non_positive_lifetime(): void
    {
        $this->expectException(SessionException::class);
        $this->expectExceptionMessage('Session lifetime must be a positive number of seconds, got 0.');

        SessionExpiry::timestampFor(0);
    }

    /**
     * KINETIS-68: time() + PHP_INT_MAX overflows PHP's native int range,
     * silently promoting to a float — confirmed directly (not assumed)
     * before this fix existed, since a store's own JSON/SQL-timestamp
     * encoding would otherwise carry that float through as
     * backend-specific corruption instead of one clear exception here.
     */
    public function test_timestamp_for_rejects_an_overflowing_lifetime(): void
    {
        $this->expectException(SessionException::class);
        $this->expectExceptionMessage((string) \PHP_INT_MAX);

        SessionExpiry::timestampFor(\PHP_INT_MAX);
    }

    /**
     * KINETIS-68 FEEDBACK: a lifetime landing well short of
     * MAX_EXPIRES_AT must not be rejected just for being large — a full
     * decade, comfortably under the cap for any real current timestamp,
     * proves the check isn't so conservative it rejects legitimately
     * long-lived sessions.
     */
    public function test_timestamp_for_accepts_a_large_but_representable_lifetime(): void
    {
        $lifetime = 315_360_000; // ten years

        $timestamp = SessionExpiry::timestampFor($lifetime);

        self::assertGreaterThan(\time(), $timestamp);
        self::assertLessThan(SessionExpiry::MAX_EXPIRES_AT, $timestamp);
    }

    /**
     * KINETIS-68 FEEDBACK: isRepresentable() is the pure, real-clock-free
     * boundary check underneath timestampFor()'s own MAX_EXPIRES_AT
     * enforcement — tested directly here with fixed, hand-picked values
     * (the maximum itself, and one past it) rather than a lifetime
     * derived from time(), which would reintroduce the exact
     * two-separate-clock-reads race timestampFor() itself is careful to
     * avoid by computing $expiresAt exactly once.
     */
    public function test_is_representable_accepts_the_maximum(): void
    {
        self::assertTrue(SessionExpiry::isRepresentable(SessionExpiry::MAX_EXPIRES_AT));
    }

    public function test_is_representable_rejects_one_past_the_maximum(): void
    {
        self::assertFalse(SessionExpiry::isRepresentable(SessionExpiry::MAX_EXPIRES_AT + 1));
    }

    public function test_is_representable_rejects_a_float(): void
    {
        self::assertFalse(SessionExpiry::isRepresentable(1.5));
    }

    /**
     * KINETIS-68 FEEDBACK 2: the same two boundary values, but through
     * timestampFor()'s own public entry point rather than the pure
     * predicate directly. $now is one hardcoded literal, used both to
     * derive $lifetime and passed explicitly into timestampFor() — no
     * call to the real time() anywhere in either test, so there is
     * nothing for a clock tick to disagree about. Deriving $lifetime
     * from a real time() call in the test and letting timestampFor()
     * make its own separate one (the earlier shape of this test) is
     * exactly the two-different-instants race this parameter exists to
     * remove, not merely narrow.
     */
    public function test_timestamp_for_accepts_a_lifetime_landing_exactly_at_the_maximum(): void
    {
        $now = 1_700_000_000;
        $lifetime = SessionExpiry::MAX_EXPIRES_AT - $now;

        self::assertSame(SessionExpiry::MAX_EXPIRES_AT, SessionExpiry::timestampFor($lifetime, $now));
    }

    public function test_timestamp_for_rejects_a_lifetime_landing_one_second_past_the_maximum(): void
    {
        $now = 1_700_000_000;
        $lifetime = SessionExpiry::MAX_EXPIRES_AT - $now + 1;

        $this->expectException(SessionException::class);
        $this->expectExceptionMessage('produces an expiry beyond');

        SessionExpiry::timestampFor($lifetime, $now);
    }
}
