<?php

declare(strict_types=1);

namespace Kinetis\Queue\Tests\Fixtures;

use Kinetis\Queue\Job;
use Kinetis\Queue\JobSerializer;
use Kinetis\Queue\QueuedJob;
use Kinetis\Queue\RenewableQueueInterface;
use RuntimeException;

/**
 * An array-backed queue that declares the renewal capability, so
 * QueueWorker's heartbeat runs against a real
 * RenewableQueueInterface rather than a stand-in for one.
 *
 * Everything the renewal tests read is observable here:
 *
 * - $renewals is one entry per renew() call, failed calls included, so
 *   "renewed at least twice" and "never renewed" are the same
 *   assertion read two ways.
 * - $overlappingRenewals counts a renew() entered while another had not
 *   returned. It is the direct test of "at most one renewal in flight":
 *   without the heartbeat's own guard, a renewal slower than the tick
 *   interval increments it.
 * - $recorder receives 'renew:start', 'renew:end' and 'ack' in the
 *   order they happened, which is how a test sees that an in-flight
 *   renewal was joined before the delivery was settled — the ordering
 *   that keeps a late renewal from overwriting a delayed release.
 *
 * $renewSeconds yields to the event loop for that long inside renew(),
 * the way a real backend's round trip does. $renewShouldFail makes every
 * renewal throw, each with its own message, so "the last exception was
 * kept" is provable rather than merely plausible.
 */
final class RenewableInMemoryQueue implements RenewableQueueInterface
{
    /** @var list<array{class: class-string<Job>, args: array<string, mixed>, maxAttempts: int|null}> */
    private array $pending = [];

    /** @var list<mixed> */
    public array $acked = [];

    /** @var list<mixed> */
    public array $released = [];

    /** @var list<int> */
    public array $releaseDelays = [];

    /** @var list<mixed> */
    public array $failed = [];

    /** @var list<mixed> the handle each renew() call carried */
    public array $renewals = [];

    public int $overlappingRenewals = 0;

    public bool $renewShouldFail = false;

    /**
     * Makes ack() throw the way a backend refusing writes does, so the
     * renewal report can be observed while a settlement exception is
     * already propagating.
     */
    public bool $ackShouldThrow = false;

    private bool $renewing = false;

    private int $nextHandle = 1;

    public function __construct(
        private readonly Recorder $recorder,
        private readonly int $visibilityTimeoutSeconds = 1,
        private readonly float $renewSeconds = 0.0,
    ) {}

    public function push(Job $job, int $delaySeconds = 0, string $queue = 'default', ?int $maxAttempts = null): void
    {
        $this->pending[] = [...JobSerializer::serialize($job), 'maxAttempts' => $maxAttempts];
    }

    public function pop(int $timeoutSeconds = 0, array $queues = ['default']): ?QueuedJob
    {
        if ($this->pending === []) {
            return null;
        }

        $next = array_shift($this->pending);

        return new QueuedJob(
            $next['class'],
            $next['args'],
            handle: $this->nextHandle++,
            queue: 'default',
            maxAttempts: $next['maxAttempts'],
        );
    }

    public function ack(QueuedJob $job): void
    {
        if ($this->ackShouldThrow) {
            throw new RuntimeException('ack() itself failed');
        }

        $this->acked[] = $job->handle;
        $this->recorder->record('ack');
    }

    public function release(QueuedJob $job, int $delaySeconds = 0): void
    {
        $this->released[] = $job->handle;
        $this->releaseDelays[] = $delaySeconds;
        $this->recorder->record('release');
    }

    public function fail(QueuedJob $job): void
    {
        $this->failed[] = $job->handle;
        $this->recorder->record('fail');
    }

    public function size(string $queue = 'default'): int
    {
        return \count($this->pending);
    }

    public function visibilityTimeoutSeconds(): int
    {
        return $this->visibilityTimeoutSeconds;
    }

    public function renew(QueuedJob $job): void
    {
        if ($this->renewing) {
            $this->overlappingRenewals++;
        }

        $this->renewing = true;
        $this->renewals[] = $job->handle;
        $this->recorder->record('renew:start');

        try {
            if ($this->renewSeconds > 0.0) {
                LoopDelay::seconds($this->renewSeconds);
            }

            if ($this->renewShouldFail) {
                throw new RuntimeException('renewal ' . \count($this->renewals) . ' refused');
            }
        } finally {
            $this->renewing = false;
            $this->recorder->record('renew:end');
        }
    }
}
