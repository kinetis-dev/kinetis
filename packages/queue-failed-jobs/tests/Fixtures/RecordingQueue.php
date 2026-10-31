<?php

declare(strict_types=1);

namespace Kinetis\QueueFailedJobs\Tests\Fixtures;

use Kinetis\Queue\Job;
use Kinetis\Queue\QueuedJob;
use Kinetis\Queue\QueueInterface;
use LogicException;

/**
 * Keeps what each push() was given; nothing else is reachable.
 */
final class RecordingQueue implements QueueInterface
{
    /** @var list<array{job: Job, queue: string, maxAttempts: ?int}> */
    public array $pushed = [];

    #[\Override]
    public function push(Job $job, int $delaySeconds = 0, string $queue = 'default', ?int $maxAttempts = null): void
    {
        $this->pushed[] = ['job' => $job, 'queue' => $queue, 'maxAttempts' => $maxAttempts];
    }

    #[\Override]
    public function pop(int $timeoutSeconds = 0, array $queues = ['default']): ?QueuedJob
    {
        throw new LogicException('A retry only pushes.');
    }

    #[\Override]
    public function ack(QueuedJob $job): void
    {
        throw new LogicException('A retry only pushes.');
    }

    #[\Override]
    public function release(QueuedJob $job, int $delaySeconds = 0): void
    {
        throw new LogicException('A retry only pushes.');
    }

    #[\Override]
    public function fail(QueuedJob $job): void
    {
        throw new LogicException('A retry only pushes.');
    }

    #[\Override]
    public function size(string $queue = 'default'): int
    {
        throw new LogicException('A retry only pushes.');
    }
}
