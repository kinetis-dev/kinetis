<?php

declare(strict_types=1);

namespace Kinetis\Async;

use Kinetis\Instrumentation\Telemetry;

/**
 * Runs $tasks concurrently: each runs in its own Fiber, and whenever one
 * suspends waiting on I/O (via Socket, Timer, or anything else built the
 * same suspend/resume way), the others keep making progress instead of
 * waiting their turn. The Fibers come from {@see FiberPool} — resident
 * workers reused across calls — because creating a Fiber per task
 * allocates and frees a whole C stack each time, and those mmap/munmap
 * cycles serialize every thread of a ZTS process against the kernel's
 * address-space lock (see the pool's docblock for the measurements).
 * {@see ConcurrentBatch} holds one call's coordination state and wait
 * mechanics.
 *
 * Every task runs to completion (successfully or not) before any
 * exception is allowed to surface: a failing task doesn't abort the ones
 * still in flight. If one or more failed, the first failure (in $tasks
 * order) is rethrown once everything has finished. A task that suspends
 * with nothing registered to resume it surfaces as a DeadlockException
 * naming the task's index.
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
    $batch = new ConcurrentBatch(\count($tasks));
    $telemetry = Telemetry::global();
    $batchToken = $telemetry->taskBatchStarted(\count($tasks));

    foreach ($tasks as $index => $task) {
        $pool->submit($batch->jobFor($index, $task));
    }

    try {
        $batch->await();
    } finally {
        $telemetry->taskBatchEnded($batchToken);
    }

    /** @var list<T> */
    return $batch->results();
}
