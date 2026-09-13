<?php

declare(strict_types=1);

namespace Kinetis\Tests\Testing;

use InvalidArgumentException;
use Kinetis\Async\Timer;
use Kinetis\Testing\Exception\LoopLivenessInconclusiveException;
use Kinetis\Testing\LoopLiveness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use RuntimeException;

use function Kinetis\Async\concurrently;

final class LoopLivenessTest extends TestCase
{
    public function test_an_operation_suspended_past_the_sentinel_lets_the_loop_turn(): void
    {
        self::assertTrue(LoopLiveness::turnedDuring(static fn () => Timer::delay(0.1)));
    }

    public function test_an_operation_blocking_past_the_sentinel_monopolizes_the_loop(): void
    {
        self::assertFalse(LoopLiveness::turnedDuring(static fn () => \usleep(100_000)));
    }

    public function test_an_operation_shorter_than_the_sentinel_is_inconclusive(): void
    {
        $watchers = EventLoop::getIdentifiers();

        try {
            LoopLiveness::turnedDuring(static fn (): null => null);
            self::fail('Expected an inconclusive observation.');
        } catch (LoopLivenessInconclusiveException) {
        }

        self::assertSame($watchers, EventLoop::getIdentifiers());
    }

    public function test_an_exception_from_the_operation_is_rethrown_unchanged(): void
    {
        $failure = new RuntimeException('upstream failed');
        $watchers = EventLoop::getIdentifiers();

        try {
            LoopLiveness::turnedDuring(static fn () => throw $failure);
            self::fail('Expected the operation\'s exception.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertSame($watchers, EventLoop::getIdentifiers());
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function invalidSentinelIntervals(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-0.02];
        yield 'infinite' => [\INF];
        yield 'not a number' => [\NAN];
    }

    #[DataProvider('invalidSentinelIntervals')]
    public function test_rejects_a_sentinel_interval_that_is_not_finite_and_positive(float $sentinelSeconds): void
    {
        $ran = false;

        try {
            LoopLiveness::turnedDuring(static function () use (&$ran): void {
                $ran = true;
            }, $sentinelSeconds);
            self::fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException) {
        }

        self::assertFalse($ran);
    }

    public function test_composes_inside_concurrently_tasks(): void
    {
        $results = concurrently([
            static fn (): bool => LoopLiveness::turnedDuring(static fn () => Timer::delay(0.2)),
            static fn (): bool => LoopLiveness::turnedDuring(static fn () => \usleep(50_000)),
        ]);

        self::assertSame([true, false], $results);
    }
}
