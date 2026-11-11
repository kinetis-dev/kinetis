<?php

declare(strict_types=1);

namespace Kinetis\QueueFailedJobs\Console;

use InvalidArgumentException;
use Kinetis\Config\Config;
use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\Console\Output;
use Kinetis\Container\RequestScope;
use Kinetis\QueueFailedJobs\FailedJobRetrier;
use Kinetis\QueueFailedJobs\RetryStatus;
use Kinetis\QueueFailedJobs\SqlFailedJobStore;

/**
 * `queue:retry <id> [<id> ...]`, or `--all` with the selection options
 * `queue:failed` takes: each record goes through {@see FailedJobRetrier},
 * which owns the retry semantics, and is reported on its own line. Any
 * record that is missing or could not be retried makes the command exit 1.
 */
final class RetryCommand
{
    private readonly FailedJobRetrier $retrier;

    public function __construct(
        SqlFailedJobStore $store,
        RequestScope $scope,
        Config $config,
        private readonly Output $output,
    ) {
        // Its own instance rather than the bound one: a named connection's
        // queue is built once per run, as `queue:work --connection` builds it.
        $this->retrier = new FailedJobRetrier($store, $scope->appScope(), $config);
    }

    #[Command(
        'queue:retry',
        description: 'Push failed jobs back onto their queue: queue:retry <id> [<id> ...] | --all [--connection=<name>|--bound] [--queue=<name>]',
    )]
    public function run(CommandArguments $arguments): int
    {
        $ids = $arguments->all();
        $all = $arguments->hasOption('all');

        if ($all === ($ids !== [])) {
            throw new InvalidArgumentException('Pass the ids of the failed jobs to retry, or --all; not both.');
        }

        $results = $all
            ? $this->retrier->retryMatching(FailedJobOptions::connection($arguments), FailedJobOptions::queue($arguments))
            : $this->retrier->retryMany($ids);

        $retried = 0;

        foreach ($results as $result) {
            switch ($result->status) {
                case RetryStatus::Retried:
                    $this->output->line("Retried \"{$result->id}\" ({$result->class}) onto queue \"{$result->queue}\".");
                    $retried++;

                    break;
                case RetryStatus::NotFound:
                    $this->output->error("No failed job \"{$result->id}\".");

                    break;
                case RetryStatus::Failed:
                    $this->output->error("Could not retry \"{$result->id}\" ({$result->class}): {$result->reason}");

                    break;
            }
        }

        $failed = \count($results) - $retried;
        $this->output->line("Retried {$retried} failed job(s); {$failed} could not be retried.");

        return $failed === 0 ? 0 : 1;
    }
}
