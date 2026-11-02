<?php

declare(strict_types=1);

namespace Kinetis\QueueFailedJobs\Tests\Fixtures;

use InvalidArgumentException;
use Kinetis\Queue\Job;

/**
 * A constructor that quotes the value it rejects, as validating
 * constructors routinely do.
 */
final readonly class RejectingJob implements Job
{
    public function __construct(
        public string $email,
    ) {
        throw new InvalidArgumentException("Not an address: {$email}");
    }

    public function handle(): void
    {
    }
}
