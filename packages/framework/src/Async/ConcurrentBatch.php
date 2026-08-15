<?php

declare(strict_types=1);

namespace Kinetis\Async;

use Kinetis\Async\Exception\DeadlockException;
use Closure;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use Throwable;

/**
 * The coordination state for one {@see concurrently()} call: which tasks
 * have finished, with what, and whether the caller is parked waiting.
 * The caller waits on a Revolt suspension that the last task to finish
 * resumes, so the event loop keeps driving everything exactly as an
 * EventLoop::run() call would, but the wait ends the moment the tasks
 * are done rather than when the loop runs out of watchers entirely.
 *
 * @internal Only concurrently() constructs one.
 */
final class ConcurrentBatch
{
    /** @var array<int, mixed> */
    private array $results = [];

    /** @var array<int, Throwable> */
    private array $failures = [];

    private int $remaining;

    private bool $awaiting = false;

    /** @var Suspension<null> */
    private readonly Suspension $suspension;

    public function __construct(private readonly int $taskCount)
    {
        $this->remaining = $taskCount;
        $this->suspension = EventLoop::getSuspension();
    }

    /**
     * Wraps one task as the exception-safe job {@see FiberPool::submit()}
     * requires: the task's outcome is recorded rather than thrown, and
     * the last job to finish resumes the parked caller.
     *
     * @param callable(): mixed $task
     * @return Closure(): void
     */
    public function jobFor(int $index, callable $task): Closure
    {
        return function () use ($index, $task): void {
            try {
                $this->results[$index] = $task();
            } catch (Throwable $e) {
                $this->failures[$index] = $e;
            }

            // Only resume a caller that actually parked: a task that
            // completes synchronously (never suspending) decrements this
            // while concurrently() is still submitting, and resuming a
            // suspension nobody suspended would leave a stale pending
            // resumption on this Fiber's cached suspension object.
            if (--$this->remaining === 0 && $this->awaiting) {
                $this->suspension->resume();
            }
        };
    }

    /**
     * Parks the caller until every task has finished. Returns
     * immediately when they all completed during submission.
     */
    public function await(): void
    {
        if ($this->remaining === 0) {
            return;
        }

        $this->awaiting = true;

        try {
            $this->suspension->suspend();
        } catch (\Error $e) {
            // Revolt throws into the suspension when the loop runs out
            // of watchers with the caller still parked — which is what a
            // task suspending its Fiber with nothing registered to
            // resume it (a raw Fiber::suspend() with no corresponding
            // EventLoop::onX() watcher) looks like from the outside:
            // Revolt only tracks watchers, never Fibers directly, so the
            // still-suspended task is invisible to it. Surface the first
            // task that never finished instead of Revolt's generic error.
            $index = $this->firstUnfinishedIndex();

            throw $index === null ? $e : DeadlockException::forTaskIndex($index);
        }
    }

    /**
     * The results in task order — after rethrowing the first failure (in
     * task order) if any task failed. Every task has already run to
     * completion by the time this is reachable.
     *
     * @return list<mixed>
     */
    public function results(): array
    {
        for ($index = 0; $index < $this->taskCount; $index++) {
            if (\array_key_exists($index, $this->failures)) {
                throw $this->failures[$index];
            }
        }

        \ksort($this->results);

        return \array_values($this->results);
    }

    private function firstUnfinishedIndex(): ?int
    {
        for ($index = 0; $index < $this->taskCount; $index++) {
            if (!\array_key_exists($index, $this->results) && !\array_key_exists($index, $this->failures)) {
                return $index;
            }
        }

        return null;
    }
}
