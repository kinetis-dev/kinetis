<?php

declare(strict_types=1);

namespace Kinetis\DatabaseBridge\Tests\Fixtures;

use Kinetis\Queue\Job;

/**
 * Never resolves TransactionGuard at all — the job scope's lazy binding
 * builds no guard and registers no cleanup for it.
 */
final readonly class NoOpJob implements Job
{
    public function handle(): void
    {
    }
}
