<?php

declare(strict_types=1);

namespace Kinetis\Async;

use Kinetis\Async\Exception\DeadlockException;
use Fiber;
use Revolt\EventLoop;
use Throwable;

/**
 * Runs $tasks concurrently: each becomes its own Fiber, and whenever one
 * suspends waiting on I/O (via Socket, Timer, or anything else built the
 * same suspend/resume way), the others keep making progress instead of
 * waiting their turn. A single EventLoop::run() call afterwards drives
 * every fiber to completion — Revolt's own run loop doesn't return until
 * there's nothing left to wait on, so it doesn't matter how many times a
 * given task suspends internally.
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
    /** @var list<Fiber<never, never, array{0: 'ok'|'error', 1: mixed}, void>> $fibers */
    $fibers = [];

    foreach ($tasks as $index => $task) {
        $fiber = new Fiber(static function () use ($task): array {
            try {
                return ['ok', $task()];
            } catch (Throwable $e) {
                return ['error', $e];
            }
        });

        $fibers[$index] = $fiber;
        $fiber->start();
    }

    EventLoop::run();

    $results = [];

    foreach ($fibers as $index => $fiber) {
        // The event loop only ran out of watchers, it never checked that
        // every Fiber it was driving actually finished — a task that
        // suspended without anything left to resume it (a raw
        // Fiber::suspend() with no corresponding watcher registered)
        // leaves EventLoop::run() free to return anyway, since Revolt has
        // no idea that Fiber is still waiting on anything. getReturn()
        // itself would throw PHP's own generic FiberError in that case;
        // caught here first so the actual problem (which task, and why)
        // is diagnosable instead.
        if (!$fiber->isTerminated()) {
            throw DeadlockException::forTaskIndex($index);
        }

        [$status, $value] = $fiber->getReturn();

        if ($status === 'error') {
            assert($value instanceof Throwable);

            throw $value;
        }

        $results[] = $value;
    }

    return $results;
}
