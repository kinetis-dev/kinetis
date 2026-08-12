<?php

declare(strict_types=1);

namespace Kinetis\Tests\Async;

use Kinetis\Async\Exception\DeadlockException;
use Kinetis\Async\Timer;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Fiber;

use function Kinetis\Async\concurrently;

final class ConcurrentlyTest extends TestCase
{
    public function test_runs_tasks_concurrently_rather_than_sequentially(): void
    {
        $start = hrtime(true);

        concurrently([
            static function (): string {
                Timer::delay(0.05);

                return 'a';
            },
            static function (): string {
                Timer::delay(0.05);

                return 'b';
            },
            static function (): string {
                Timer::delay(0.05);

                return 'c';
            },
        ]);

        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        // Sequential would be ~150ms+; truly concurrent should land near
        // the single longest delay (~50ms). 100ms is a safe middle
        // threshold that fails a serialized implementation without being
        // sensitive to normal scheduling jitter.
        self::assertLessThan(100, $elapsedMs);
    }

    public function test_returns_results_in_the_same_order_as_the_input_tasks(): void
    {
        $results = concurrently([
            static function (): string {
                Timer::delay(0.03);

                return 'slow';
            },
            static function (): string {
                return 'fast';
            },
        ]);

        self::assertSame(['slow', 'fast'], $results);
    }

    public function test_every_task_runs_even_if_an_earlier_one_throws(): void
    {
        $ran = [];

        try {
            concurrently([
                static function () use (&$ran): void {
                    $ran[] = 'first';

                    throw new RuntimeException('boom');
                },
                static function () use (&$ran): string {
                    Timer::delay(0.01);
                    $ran[] = 'second';

                    return 'ok';
                },
            ]);

            self::fail('Expected a RuntimeException.');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame(['first', 'second'], $ran);
    }

    /**
     * A task that suspends its Fiber with nothing registered to resume it
     * (a raw Fiber::suspend() call with no corresponding
     * EventLoop::onX() watcher) leaves EventLoop::run() free to return
     * anyway, since Revolt only tracks watchers, never Fibers directly —
     * the still-suspended Fiber is invisible to it.
     */
    public function test_a_deadlocked_task_throws_a_diagnosable_exception_not_a_raw_fiber_error(): void
    {
        $this->expectException(DeadlockException::class);
        $this->expectExceptionMessageMatches('/index 0/');

        concurrently([
            static function (): void {
                Fiber::suspend();
            },
        ]);
    }

    public function test_a_deadlock_is_diagnosed_by_its_own_index_among_several_tasks(): void
    {
        $this->expectException(DeadlockException::class);
        $this->expectExceptionMessageMatches('/index 1/');

        concurrently([
            static fn (): string => 'fine',
            static function (): void {
                Fiber::suspend();
            },
        ]);
    }
}
