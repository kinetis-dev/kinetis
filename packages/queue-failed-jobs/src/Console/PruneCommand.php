<?php

declare(strict_types=1);

namespace Kinetis\QueueFailedJobs\Console;

use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\QueueFailedJobs\SqlFailedJobStore;

/**
 * Deletes every failed-job record older than --hours. The age has no
 * default: nothing is retained or pruned automatically, and this command
 * deletes only what it is told to.
 */
final readonly class PruneCommand
{
    /** @param resource $output */
    public function __construct(
        private SqlFailedJobStore $store,
        private mixed $output = \STDOUT,
    ) {}

    #[Command('queue:prune', description: 'Delete failed jobs older than an age: queue:prune --hours=<n>')]
    public function run(CommandArguments $arguments): int
    {
        $hours = FailedJobOptions::integer($arguments, 'hours', 0);
        $pruned = $this->store->prune($hours * 3600);

        fwrite($this->output, "Pruned {$pruned} failed job(s) older than {$hours} hour(s).\n");

        return 0;
    }
}
