<?php

declare(strict_types=1);

namespace Kinetis\QueueFailedJobs\Tests\Fixtures;

use Kinetis\Queue\Attributes\Sensitive;
use Kinetis\Queue\Job;

final readonly class SensitiveJob implements Job
{
    public function __construct(
        public int $userId,
        #[Sensitive]
        public string $token,
    ) {}

    public function handle(): void
    {
    }
}
