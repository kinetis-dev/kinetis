<?php

declare(strict_types=1);

namespace Kinetis\QueueFailedJobs;

/**
 * What {@see FailedJobRetrier} did with one record. $class and $queue are
 * the record's, set whenever the record existed; $reason says why a
 * Failed retry failed. None of them carries a job argument.
 */
final readonly class RetryResult
{
    private function __construct(
        public string $id,
        public RetryStatus $status,
        public ?string $class,
        public ?string $queue,
        public ?string $reason,
    ) {}

    public static function retried(string $id, string $class, string $queue): self
    {
        return new self($id, RetryStatus::Retried, $class, $queue, null);
    }

    public static function notFound(string $id): self
    {
        return new self($id, RetryStatus::NotFound, null, null, null);
    }

    public static function failed(string $id, string $class, string $queue, string $reason): self
    {
        return new self($id, RetryStatus::Failed, $class, $queue, $reason);
    }
}
