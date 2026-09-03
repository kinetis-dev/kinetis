<?php

declare(strict_types=1);

namespace Kinetis\SimpleCache\Tests\Cluster;

use Amp\Redis\RedisException;
use Kinetis\SimpleCache\Cluster\ClusterRedirect;
use Kinetis\SimpleCache\Cluster\ClusterRedirectKind;
use PHPUnit\Framework\TestCase;

/**
 * ClusterRedirect::tryParse() is pure and network-free — every case here
 * needs no real cluster, unlike the live MOVED/ASK/sequencing proof in
 * ClusteredRedisSimpleCacheIntegrationTest, which needs a real
 * migrating slot to exercise the server's own protocol behavior.
 */
final class ClusterRedirectTest extends TestCase
{
    public function test_parses_a_moved_redirect(): void
    {
        $redirect = ClusterRedirect::tryParse('MOVED 3999 127.0.0.1:6381');

        self::assertNotNull($redirect);
        self::assertSame(ClusterRedirectKind::Moved, $redirect->kind);
        self::assertSame(3999, $redirect->slot);
        self::assertSame('tcp://127.0.0.1:6381', $redirect->target->toUri());
    }

    public function test_parses_an_ask_redirect(): void
    {
        $redirect = ClusterRedirect::tryParse('ASK 3999 127.0.0.1:6381');

        self::assertNotNull($redirect);
        self::assertSame(ClusterRedirectKind::Ask, $redirect->kind);
        self::assertSame(3999, $redirect->slot);
    }

    /**
     * A discovered redirect target is written in the identical bracketed
     * form a seed config string uses — ClusterEndpoint::parse() handles
     * it the same way for both.
     */
    public function test_parses_a_bracketed_ipv6_target(): void
    {
        $redirect = ClusterRedirect::tryParse('MOVED 3999 [2001:db8::10]:6379');

        self::assertNotNull($redirect);
        self::assertSame('tcp://[2001:db8::10]:6379', $redirect->target->toUri());
    }

    public function test_accepts_slot_zero(): void
    {
        $redirect = ClusterRedirect::tryParse('ASK 0 10.0.0.5:7000');

        self::assertNotNull($redirect);
        self::assertSame(0, $redirect->slot);
    }

    public function test_accepts_the_maximum_valid_slot(): void
    {
        $redirect = ClusterRedirect::tryParse('ASK 16383 10.0.0.5:7000');

        self::assertNotNull($redirect);
        self::assertSame(16383, $redirect->slot);
    }

    public function test_rejects_a_slot_above_the_valid_range(): void
    {
        $this->expectException(RedisException::class);
        $this->expectExceptionMessage('slot 16384 is outside the valid 0-16383 range');

        ClusterRedirect::tryParse('MOVED 16384 10.0.0.5:7000');
    }

    public function test_rejects_a_negative_slot(): void
    {
        $this->expectException(RedisException::class);
        $this->expectExceptionMessage('invalid slot "-1"');

        ClusterRedirect::tryParse('MOVED -1 10.0.0.5:7000');
    }

    public function test_rejects_a_non_numeric_slot(): void
    {
        $this->expectException(RedisException::class);
        $this->expectExceptionMessage('invalid slot "abc"');

        ClusterRedirect::tryParse('MOVED abc 10.0.0.5:7000');
    }

    /**
     * A grossly overlong digit string is still structurally "all
     * digits" — PHP's (int) cast saturates rather than wrapping, so
     * this must still land safely above the valid range rather than
     * wrapping into something that passes.
     */
    public function test_rejects_a_slot_with_an_overflowing_digit_string(): void
    {
        $this->expectException(RedisException::class);
        $this->expectExceptionMessage('is outside the valid 0-16383 range');

        ClusterRedirect::tryParse('MOVED 999999999999999999999999999999 10.0.0.5:7000');
    }

    public function test_rejects_a_truncated_message_with_only_the_kind(): void
    {
        $this->expectException(RedisException::class);
        $this->expectExceptionMessage('expected "MOVED <slot> <endpoint>", got "MOVED"');

        ClusterRedirect::tryParse('MOVED');
    }

    public function test_rejects_a_truncated_message_missing_the_endpoint(): void
    {
        $this->expectException(RedisException::class);
        $this->expectExceptionMessage('expected "MOVED <slot> <endpoint>", got "MOVED 3999"');

        ClusterRedirect::tryParse('MOVED 3999');
    }

    public function test_rejects_a_malformed_target_endpoint(): void
    {
        $this->expectException(RedisException::class);
        $this->expectExceptionMessage('invalid target endpoint "not-a-valid-endpoint"');

        ClusterRedirect::tryParse('MOVED 3999 not-a-valid-endpoint');
    }

    /**
     * An unbracketed IPv6 target is genuinely ambiguous — the same
     * bug ClusterEndpoint::parse() itself already rejects for a seed
     * address, surfacing here as a malformed-redirect error instead of
     * the seed-configuration exception type.
     */
    public function test_rejects_an_unbracketed_ipv6_target(): void
    {
        $this->expectException(RedisException::class);
        $this->expectExceptionMessage('invalid target endpoint "2001:db8::10:6379"');

        ClusterRedirect::tryParse('MOVED 3999 2001:db8::10:6379');
    }

    public function test_returns_null_for_an_unrelated_error_message(): void
    {
        self::assertNull(
            ClusterRedirect::tryParse('WRONGTYPE Operation against a key holding the wrong kind of value'),
        );
    }

    public function test_returns_null_for_an_unrelated_all_caps_error_kind(): void
    {
        self::assertNull(ClusterRedirect::tryParse('NOSCRIPT No matching script'));
    }

    /**
     * The kind must match exactly — a word merely starting with "MOVED"
     * is not a redirect at all, not a malformed one.
     */
    public function test_returns_null_for_a_kind_that_only_starts_with_a_redirect_keyword(): void
    {
        self::assertNull(ClusterRedirect::tryParse('MOVEDSOMETHING 3999 10.0.0.5:7000'));
    }

    public function test_returns_null_for_an_empty_message(): void
    {
        self::assertNull(ClusterRedirect::tryParse(''));
    }

    public function test_returns_null_for_the_bare_asking_command_name(): void
    {
        self::assertNull(ClusterRedirect::tryParse('ASKING'));
    }
}
