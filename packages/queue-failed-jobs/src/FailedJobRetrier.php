<?php

declare(strict_types=1);

namespace Kinetis\QueueFailedJobs;

use InvalidArgumentException;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Queue\DisposableQueueInterface;
use Kinetis\Queue\Exception\JobReconstructionException;
use Kinetis\Queue\JobSerializer;
use Kinetis\Queue\QueueFactory;
use Kinetis\Queue\QueueInterface;
use Throwable;

/**
 * Pushes recorded failed jobs back onto the queue they failed on, then
 * forgets each record — what `queue:retry` runs, for application code.
 * Bound application-scoped by {@see PackageBootstrap}.
 *
 * A record's target is resolved the way `queue:work` resolves its own: a
 * null connection pushes onto the bound QueueInterface, and a named one
 * onto that connection built through QueueFactory — once per connection
 * for this service's lifetime, with a disposable backend's dispose()
 * registered on the application scope. The job is reconstructed from the
 * stored class and arguments and pushed with its stored queue and
 * maxAttempts.
 *
 * A retry that fails — a class that no longer autoloads or no longer
 * accepts the stored arguments, a connection no longer configured, a push
 * the backend refuses — is a Failed result and keeps its record; a batch
 * goes on to the next one. A failure of the store itself propagates.
 */
final class FailedJobRetrier
{
    private const int PAGE_SIZE = 100;

    /** @var array<string, QueueInterface> keyed by connection name, '' for the bound queue */
    private array $queues = [];

    public function __construct(
        private readonly SqlFailedJobStore $store,
        private readonly AppScope $app,
        private readonly Config $config,
    ) {}

    /**
     * The push comes before the forget, so a failed push keeps the record,
     * and an interruption between the two leaves it too: retrying it
     * again pushes the job a second time. A store refusing the forget
     * after a successful push propagates rather than returning Failed —
     * the job is on its queue again.
     */
    public function retry(string $id): RetryResult
    {
        $stored = $this->store->find($id);

        return $stored === null ? RetryResult::notFound($id) : $this->retryRecord($stored);
    }

    /**
     * One result per id, in order; see retry().
     *
     * @param list<string> $ids
     * @return list<RetryResult>
     */
    public function retryMany(array $ids): array
    {
        return array_map($this->retry(...), $ids);
    }

    /**
     * Retries the records `queue:failed` selects with the same filters —
     * $connection in {@see SqlFailedJobStore::list()}'s terms: false for
     * any connection, null for the bound queue only, or a connection
     * name — oldest first in keyset pages. Stops at the first record that
     * failed after this call started, so a job failing again meanwhile is
     * left for the next call, and after $limit records when one is given.
     *
     * @return list<RetryResult>
     */
    public function retryMatching(string|false|null $connection = false, ?string $queue = null, ?int $limit = null): array
    {
        if ($limit !== null && $limit < 1) {
            throw new InvalidArgumentException("The retry limit must be at least 1, got {$limit}.");
        }

        $startedAt = time();
        $results = [];
        $after = null;

        do {
            $pageSize = $limit === null ? self::PAGE_SIZE : min(self::PAGE_SIZE, $limit - \count($results));
            $page = $this->store->list($pageSize, $connection, $queue, $after);

            foreach ($page as $stored) {
                if ($stored->job->failedAt > $startedAt) {
                    return $results;
                }

                $results[] = $this->retryRecord($stored);
                $after = $stored;
            }
        } while (\count($page) === $pageSize && \count($results) !== $limit);

        return $results;
    }

    private function retryRecord(StoredFailedJob $stored): RetryResult
    {
        $job = $stored->job;

        try {
            $this->queueFor($job->connection)->push(
                JobSerializer::deserializeJob($job->class, $job->args),
                queue: $job->queue,
                maxAttempts: $job->maxAttempts,
            );
        } catch (Throwable $e) {
            return RetryResult::failed($stored->id, $job->class, $job->queue, self::reason($job->class, $e));
        }

        // Outside the catch: see retry().
        $this->store->forget($stored->id);

        return RetryResult::retried($stored->id, $job->class, $job->queue);
    }

    /**
     * A JobReconstructionException chaining a cause carries the
     * constructor's own message, which is free to quote the argument it
     * rejected, so the cause's class stands in for it. Every other
     * reconstruction message names only the class and a parameter, and a
     * resolution or push failure describes the backend.
     */
    private static function reason(string $class, Throwable $e): string
    {
        $cause = $e instanceof JobReconstructionException ? $e->getPrevious() : null;

        return $cause === null
            ? $e->getMessage()
            : "Cannot reconstruct \"{$class}\": its constructor threw " . $cause::class . '.';
    }

    private function queueFor(?string $connection): QueueInterface
    {
        $key = $connection ?? '';

        if (isset($this->queues[$key])) {
            return $this->queues[$key];
        }

        if ($connection === null) {
            /** @var QueueInterface $queue */
            $queue = $this->app->get(QueueInterface::class);
        } else {
            $queue = QueueFactory::fromConfig($this->config, $connection);

            if ($queue instanceof DisposableQueueInterface) {
                $this->app->onDispose($queue->dispose(...));
            }
        }

        return $this->queues[$key] = $queue;
    }
}
