<?php

declare(strict_types=1);

namespace Kinetis\Async;

use Kinetis\Async\Exception\DeadlockException;
use Revolt\EventLoop;
use Throwable;

/**
 * Runs $tasks concurrently: each runs in its own Fiber, and whenever one
 * suspends waiting on I/O (via Socket, Timer, or anything else built the
 * same suspend/resume way), the others keep making progress instead of
 * waiting their turn. The Fibers come from {@see FiberPool} — resident
 * workers reused across calls — because creating a Fiber per task
 * allocates and frees a whole C stack each time, and those mmap/munmap
 * cycles serialize every thread of a ZTS process against the kernel's
 * address-space lock (see the pool's docblock for the measurements).
 *
 * The caller waits on a Revolt suspension that the last task to finish
 * resumes, so the event loop keeps driving everything exactly as an
 * EventLoop::run() call would, but the wait ends the moment the tasks
 * are done rather than when the loop runs out of watchers entirely.
 *
 * Every task runs to completion (successfully or not) before any
 * exception is allowed to surface: a failing task doesn't abort the ones
 * still in flight. If one or more failed, the first failure (in $tasks
 * order) is rethrown once everything has finished.
 *
 * @template T
 * @param list<callable(): T> $tasks
 * @return list<T>
 */
function concurrently(array $tasks): array
{
    if ($tasks === []) {
        return [];
    }

    $pool = FiberPool::instance();

    /** @var array<int, T> $results */
    $results = [];

    /** @var array<int, Throwable> $failures */
    $failures = [];

    $remaining = \count($tasks);
    $suspension = EventLoop::getSuspension();
    $awaiting = false;

    foreach ($tasks as $index => $task) {
        $pool->submit(static function () use ($task, $index, &$results, &$failures, &$remaining, &$awaiting, $suspension): void {
            try {
                $results[$index] = $task();
            } catch (Throwable $e) {
                $failures[$index] = $e;
            }

            // Only resume a caller that actually parked: a task that
            // completes synchronously (never suspending) decrements this
            // while concurrently() is still submitting, and resuming a
            // suspension nobody suspended would leave a stale pending
            // resumption on this Fiber's cached suspension object.
            // PHPStan's flow analysis only sees $awaiting's value at the
            // point the closure is *defined* (false), not the enclosing
            // scope's later reassignment before suspending — the same
            // verified false positive documented in Socket.php.
            if (--$remaining === 0 && $awaiting) { // @phpstan-ignore booleanAnd.rightAlwaysFalse
                $suspension->resume();
            }
        });
    }

    if ($remaining > 0) {
        $awaiting = true;

        try {
            $suspension->suspend();
        } catch (\Error $e) {
            // Revolt throws into the suspension when the loop runs out
            // of watchers with the caller still parked — which is what a
            // task suspending its Fiber with nothing registered to
            // resume it (a raw Fiber::suspend() with no corresponding
            // EventLoop::onX() watcher) looks like from the outside:
            // Revolt only tracks watchers, never Fibers directly, so the
            // still-suspended task is invisible to it. Surface the first
            // task that never finished instead of Revolt's generic error.
            foreach ($tasks as $index => $_) {
                if (!\array_key_exists($index, $results) && !\array_key_exists($index, $failures)) {
                    throw DeadlockException::forTaskIndex($index);
                }
            }

            throw $e;
        }
    }

    foreach ($tasks as $index => $_) {
        if (\array_key_exists($index, $failures)) {
            throw $failures[$index];
        }
    }

    \ksort($results);

    return \array_values($results);
}
