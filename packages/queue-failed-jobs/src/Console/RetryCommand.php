<?php

declare(strict_types=1);

namespace Kinetis\QueueFailedJobs\Console;

use InvalidArgumentException;
use Kinetis\Config\Config;
use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\Container\RequestScope;
use Kinetis\Queue\DisposableQueueInterface;
use Kinetis\Queue\JobSerializer;
use Kinetis\Queue\QueueFactory;
use Kinetis\Queue\QueueInterface;
use Kinetis\QueueFailedJobs\SqlFailedJobStore;
use Kinetis\QueueFailedJobs\StoredFailedJob;
use Throwable;

/**
 * Pushes recorded failed jobs back onto the queue they failed on, then
 * forgets each record: `queue:retry <id> [<id> ...]`, or `--all` with the
 * selection options `queue:failed` takes.
 *
 * A record's target is resolved the way `queue:work` resolves its own: a
 * null connection pushes onto the bound QueueInterface, and a named one
 * onto that connection built through QueueFactory — once per connection
 * per run, with a disposable backend's dispose() registered on the
 * application scope. The job is reconstructed from the stored class and
 * arguments and pushed with its stored maxAttempts.
 *
 * A record whose retry fails — a class that no longer autoloads, a
 * connection no longer configured, a push the backend refuses — is
 * reported and kept, and the command exits 1. The push comes before the
 * forget, so an interruption between the two leaves the record, and
 * retrying it again pushes the job a second time.
 *
 * --all walks the records oldest first in keyset pages and stops at the
 * first record that failed after the run started, so a job that fails
 * again while the run is in progress is left for the next one.
 */
final class RetryCommand
{
    private const int PAGE_SIZE = 100;

    /** @var array<string, QueueInterface> keyed by connection name, '' for the bound queue */
    private array $queues = [];

    /** @param resource $output */
    public function __construct(
        private readonly SqlFailedJobStore $store,
        private readonly RequestScope $scope,
        private readonly Config $config,
        private readonly mixed $output = \STDOUT,
    ) {}

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

        [$retried, $failed] = $all ? $this->retryAll($arguments) : $this->retryIds($ids);

        $this->line("Retried {$retried} failed job(s); {$failed} could not be retried.");

        return $failed === 0 ? 0 : 1;
    }

    /**
     * @param list<string> $ids
     * @return array{int, int}
     */
    private function retryIds(array $ids): array
    {
        $retried = 0;
        $failed = 0;

        foreach ($ids as $id) {
            $stored = $this->store->find($id);

            if ($stored === null) {
                $this->line("No failed job \"{$id}\".");
                $failed++;

                continue;
            }

            $this->retry($stored) ? $retried++ : $failed++;
        }

        return [$retried, $failed];
    }

    /**
     * @return array{int, int}
     */
    private function retryAll(CommandArguments $arguments): array
    {
        $connection = FailedJobOptions::connection($arguments);
        $queue = FailedJobOptions::queue($arguments);
        $startedAt = time();

        $retried = 0;
        $failed = 0;
        $after = null;

        do {
            $page = $this->store->list(self::PAGE_SIZE, $connection, $queue, $after);

            foreach ($page as $stored) {
                if ($stored->job->failedAt > $startedAt) {
                    return [$retried, $failed];
                }

                $this->retry($stored) ? $retried++ : $failed++;
                $after = $stored;
            }
        } while (\count($page) === self::PAGE_SIZE);

        return [$retried, $failed];
    }

    private function retry(StoredFailedJob $stored): bool
    {
        $job = $stored->job;

        try {
            $this->queueFor($job->connection)->push(
                JobSerializer::deserializeJob($job->class, $job->args),
                queue: $job->queue,
                maxAttempts: $job->maxAttempts,
            );
        } catch (Throwable $e) {
            $this->line("Could not retry \"{$stored->id}\" ({$job->class}): {$e->getMessage()}");

            return false;
        }

        // Outside the catch: the job is on its queue again, so a store
        // refusing the delete is a failure to report as itself, not a
        // retry to count as failed.
        $this->store->forget($stored->id);
        $this->line("Retried \"{$stored->id}\" ({$job->class}) onto queue \"{$job->queue}\".");

        return true;
    }

    private function queueFor(?string $connection): QueueInterface
    {
        $key = $connection ?? '';

        if (isset($this->queues[$key])) {
            return $this->queues[$key];
        }

        if ($connection === null) {
            /** @var QueueInterface $queue */
            $queue = $this->scope->get(QueueInterface::class);
        } else {
            $queue = QueueFactory::fromConfig($this->config, $connection);

            if ($queue instanceof DisposableQueueInterface) {
                $this->scope->appScope()->onDispose($queue->dispose(...));
            }
        }

        return $this->queues[$key] = $queue;
    }

    private function line(string $text): void
    {
        fwrite($this->output, $text . "\n");
    }
}
