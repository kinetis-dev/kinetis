<?php

declare(strict_types=1);

namespace Kinetis\Console\Events;

use Throwable;

/**
 * Dispatched by bin/kinetis's own top-level catch when a command throws.
 * Commands are typically run outside any request context — cron, a
 * Kubernetes CronJob, a deploy step — so this is the only place that can
 * observe a failure without wrapping every command's own body in a
 * try/catch of its own.
 */
final readonly class CommandFailed
{
    public function __construct(
        public string $commandName,
        public Throwable $exception,
    ) {}
}
