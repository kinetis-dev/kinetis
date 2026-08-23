<?php

declare(strict_types=1);

namespace Kinetis\Tests\Async;

use Kinetis\Async\Exception\DeadlockException;
use Kinetis\Async\Timer;
use PHPUnit\Framework\TestCase;
use Fiber;

use function Kinetis\Async\concurrently;

/**
 * Pins the property FiberPool exists for: tasks run on *resident*
 * Fibers that survive between concurrently() calls, instead of a fresh
 * Fiber (and a fresh mmap'd C stack) per task.
 */
final class FiberPoolTest extends TestCase
{
    public function test_fibers_are_reused_across_concurrently_calls(): void
    {
        // Timer::delay forces both tasks to overlap, so they must get
        // two distinct residents (a purely synchronous task would just
        // run on the same worker its predecessor released).
        $suspending = static function (): ?Fiber {
            Timer::delay(0.005);

            return Fiber::getCurrent();
        };

        $first = concurrently([$suspending, $suspending]);
        $second = concurrently([$suspending, $suspending]);

        self::assertNotNull($first[0]);
        self::assertNotNull($first[1]);
        // Two overlapping tasks really got two distinct Fibers…
        self::assertNotSame($first[0], $first[1]);
        // …and the next call ran on those same residents, not new ones.
        self::assertContains($second[0], $first);
        self::assertContains($second[1], $first);
    }

    public function test_a_suspending_task_does_not_share_its_fiber_with_a_parallel_task(): void
    {
        $fibers = concurrently([
            static function (): ?Fiber {
                Timer::delay(0.01);

                return Fiber::getCurrent();
            },
            static function (): ?Fiber {
                Timer::delay(0.01);

                return Fiber::getCurrent();
            },
        ]);

        self::assertNotSame($fibers[0], $fibers[1]);
    }

    public function test_a_deadlocked_worker_is_never_reused(): void
    {
        try {
            concurrently([
                static function (): void {
                    Fiber::suspend();
                },
            ]);
            self::fail('Expected a DeadlockException.');
        } catch (DeadlockException) {
            // The worker Fiber above is still suspended mid-task; the
            // pool must not hand it out again.
        }

        $results = concurrently([
            static function (): string {
                Timer::delay(0.005);

                return 'healthy';
            },
        ]);

        self::assertSame(['healthy'], $results);
    }

    public function test_nested_concurrently_completes(): void
    {
        $results = concurrently([
            static fn (): array => concurrently([
                static function (): string {
                    Timer::delay(0.005);

                    return 'inner-a';
                },
                static fn (): string => 'inner-b',
            ]),
            static function (): string {
                Timer::delay(0.005);

                return 'outer';
            },
        ]);

        self::assertSame([['inner-a', 'inner-b'], 'outer'], $results);
    }

    public function test_empty_task_list_returns_empty_results(): void
    {
        self::assertSame([], concurrently([]));
    }
}
