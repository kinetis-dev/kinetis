<?php

declare(strict_types=1);

namespace Kinetis\Tests\Async;

use Kinetis\Async\Exception\DeadlockException;
use Kinetis\Async\Timer;
use Kinetis\Instrumentation\NullTelemetry;
use Kinetis\Instrumentation\Telemetry;
use Kinetis\Tests\Instrumentation\ThrowingTelemetry;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Fiber;

use function Kinetis\Async\concurrently;

final class ConcurrentlyTest extends TestCase
{
    /**
     * Telemetry::global() is a real per-process singleton — any test
     * that swaps in a custom backend must restore a clean one afterward,
     * or a later, unrelated test would silently observe it.
     */
    protected function tearDown(): void
    {
        Telemetry::global()->swap(new NullTelemetry());
    }

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

    /**
     * ConcurrentBatch::jobFor() calls taskStarted() before its own try
     * and taskEnded() before decrementing $remaining; concurrently()
     * itself calls taskBatchEnded() in a finally around await(). A
     * throwing telemetry backend at any of those points would otherwise
     * be able to unwind the resident FiberPool worker, replace a task's
     * real outcome, or prevent the completion accounting that lets the
     * parked caller ever resume — Telemetry containing every hook itself
     * closes all of that at once, with no change needed here.
     */
    public function test_completes_every_task_and_returns_the_real_results_even_with_a_failing_telemetry_backend(): void
    {
        Telemetry::global()->swap(new ThrowingTelemetry());

        $results = concurrently([
            static function (): string {
                Timer::delay(0.01);

                return 'slow';
            },
            static fn (): string => 'fast',
            static fn (): string => 'fastest',
        ]);

        self::assertSame(['slow', 'fast', 'fastest'], $results);
    }

    public function test_rethrows_the_real_task_failure_even_with_a_failing_telemetry_backend(): void
    {
        Telemetry::global()->swap(new ThrowingTelemetry());

        $ran = [];

        try {
            concurrently([
                static function () use (&$ran): void {
                    $ran[] = 'first';

                    throw new RuntimeException('the real task failure');
                },
                static function () use (&$ran): string {
                    Timer::delay(0.01);
                    $ran[] = 'second';

                    return 'ok';
                },
            ]);

            self::fail('Expected a RuntimeException.');
        } catch (RuntimeException $e) {
            self::assertSame('the real task failure', $e->getMessage(), 'the real task exception, not the telemetry backend\'s own failure, is what propagated');
        }

        self::assertSame(['first', 'second'], $ran, 'both tasks still ran to completion despite the failing telemetry backend');
    }
}
