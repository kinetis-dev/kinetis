<?php

declare(strict_types=1);

namespace Kinetis\Async\Exception;

use RuntimeException;

final class DeadlockException extends RuntimeException
{
    public static function forTaskIndex(int $index): self
    {
        return new self(
            "concurrently() task at index {$index} suspended its Fiber with nothing left to resume it — " .
            'the event loop ran out of watchers to wait on while this task was still suspended. ' .
            'This usually means Fiber::suspend() was called directly with no corresponding ' .
            'EventLoop::onX() watcher registered to resume it.',
        );
    }
}
