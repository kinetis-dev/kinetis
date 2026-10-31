<?php

declare(strict_types=1);

namespace Kinetis\QueueFailedJobs;

use Kinetis\Queue\FailedJob;

/**
 * One row of `kinetis_failed_jobs`: the id {@see SqlFailedJobStore::record()}
 * returned for it, and the job as it was recorded.
 */
final readonly class StoredFailedJob
{
    public function __construct(
        public string $id,
        public FailedJob $job,
    ) {}
}
