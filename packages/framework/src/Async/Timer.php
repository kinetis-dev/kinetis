<?php

declare(strict_types=1);

namespace Kinetis\Async;

use Fiber;
use Revolt\EventLoop;

/**
 * A Fiber-suspending delay, built the same way Socket's awaitReadable/
 * awaitWritable are: register a Revolt watcher, suspend, resume when it
 * fires. Exists mainly as a deterministic way to prove concurrently()
 * actually overlaps work instead of running it sequentially, without
 * depending on real socket/network timing in tests.
 */
final class Timer
{
    public static function delay(float $seconds): void
    {
        $fiber = Fiber::getCurrent();

        EventLoop::delay($seconds, static function () use ($fiber): void {
            $fiber?->resume();
        });

        Fiber::suspend();
    }
}
