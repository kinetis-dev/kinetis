<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use Kinetis\Queue\Job;
use Kinetis\Queue\QueuedJob;
use Kinetis\Queue\QueueInterface;

/**
 * Accepts a job and holds it, so a test can assert something was queued
 * without a Redis to queue it into — and without running it, which
 * SyncQueue would, turning a ping the test expects to find pending into
 * one already ponged.
 */
final class RecordingQueue implements QueueInterface
{
    /** @var list<Job> */
    public array $pushed = [];

    #[\Override]
    public function push(Job $job, int $delaySeconds = 0, string $queue = 'default', ?int $maxAttempts = null): void
    {
        $this->pushed[] = $job;
    }

    #[\Override]
    public function pop(int $timeoutSeconds = 0, array $queues = ['default']): ?QueuedJob
    {
        return null;
    }

    #[\Override]
    public function ack(QueuedJob $job): void {}

    #[\Override]
    public function release(QueuedJob $job): void {}

    #[\Override]
    public function fail(QueuedJob $job): void {}

    #[\Override]
    public function size(string $queue = 'default'): int
    {
        return \count($this->pushed);
    }

    #[\Override]
    public function clear(string $queue = 'default'): int
    {
        $count = \count($this->pushed);
        $this->pushed = [];

        return $count;
    }
}
