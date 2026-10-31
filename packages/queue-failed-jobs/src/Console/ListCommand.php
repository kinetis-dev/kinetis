<?php

declare(strict_types=1);

namespace Kinetis\QueueFailedJobs\Console;

use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\Queue\JobSerializer;
use Kinetis\QueueFailedJobs\SqlFailedJobStore;
use Kinetis\QueueFailedJobs\StoredFailedJob;
use Throwable;

/**
 * Prints recorded failed jobs, oldest first, up to --limit (default 50).
 *
 * Arguments are printed through JobSerializer::redact(), so a
 * #[Sensitive] value is never written to the terminal: redact() already
 * redacts every argument of a class that no longer autoloads, and a
 * class whose reflection throws gets the same fully-redacted shape. The
 * stored payload itself is untouched.
 */
final readonly class ListCommand
{
    private const int DEFAULT_LIMIT = 50;

    /** @param resource $output */
    public function __construct(
        private SqlFailedJobStore $store,
        private mixed $output = \STDOUT,
    ) {}

    #[Command(
        'queue:failed',
        description: 'List failed jobs, oldest first: queue:failed [--connection=<name>|--bound] [--queue=<name>] [--limit=50]',
    )]
    public function run(CommandArguments $arguments): int
    {
        $connection = FailedJobOptions::connection($arguments);
        $queue = FailedJobOptions::queue($arguments);
        $limit = FailedJobOptions::integer($arguments, 'limit', 1, self::DEFAULT_LIMIT);

        $jobs = $this->store->list($limit, $connection, $queue);

        if ($jobs === []) {
            fwrite($this->output, "No failed jobs.\n");

            return 0;
        }

        foreach ($jobs as $stored) {
            fwrite($this->output, self::describe($stored));
        }

        return 0;
    }

    private static function describe(StoredFailedJob $stored): string
    {
        $job = $stored->job;

        try {
            $args = JobSerializer::redact($job->class, $job->args);
        } catch (Throwable) {
            $args = array_fill_keys(array_keys($job->args), JobSerializer::REDACTED);
        }

        return sprintf(
            "%s  %s  connection: %s  queue: %s  %s  attempts: %d/%s\n    %s: %s\n    args: %s\n",
            $stored->id,
            gmdate('Y-m-d H:i:s', $job->failedAt) . ' UTC',
            $job->connection ?? '(bound)',
            $job->queue,
            $job->class,
            $job->attempts,
            $job->maxAttempts ?? 'worker default',
            $job->exceptionClass,
            $job->exceptionMessage,
            json_encode($args, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
        );
    }
}
