<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests\Support;

use Kinetis\Session\Exception\SessionException;
use Kinetis\Session\Support\SessionExpiry;
use PHPUnit\Framework\TestCase;

final class SessionExpiryTest extends TestCase
{
    public function test_a_zero_lifetime_is_rejected(): void
    {
        $this->expectException(SessionException::class);
        $this->expectExceptionMessage('Session lifetime must be a positive number of seconds, got 0.');

        SessionExpiry::timestampFor(0);
    }

    /**
     * The label is what a misconfigured environment variable is named
     * by, so the caller's own label reaches the message rather than the
     * generic default.
     */
    public function test_a_negative_lifetime_is_rejected_under_the_given_label(): void
    {
        $this->expectException(SessionException::class);
        $this->expectExceptionMessage('SESSION_LIFETIME must be a positive number of seconds, got -1.');

        SessionExpiry::timestampFor(-1, 'SESSION_LIFETIME');
    }

    /**
     * time() + PHP_INT_MAX leaves PHP's integer range, and an
     * overflowing addition promotes to float — rejected before the sum
     * is computed, so no store ever encodes one.
     */
    public function test_a_lifetime_that_would_overflow_the_timestamp_is_rejected(): void
    {
        $this->expectException(SessionException::class);
        $this->expectExceptionMessage((string) \PHP_INT_MAX);

        SessionExpiry::timestampFor(\PHP_INT_MAX);
    }

    /**
     * A tolerant bracket rather than exact equality: time() can tick
     * over between this test's own reads and the one inside
     * timestampFor().
     */
    public function test_an_ordinary_lifetime_returns_now_plus_that_many_seconds(): void
    {
        $before = \time();
        $timestamp = SessionExpiry::timestampFor(3600);
        $after = \time();

        self::assertGreaterThanOrEqual($before + 3600, $timestamp);
        self::assertLessThanOrEqual($after + 3600, $timestamp);
    }
}
