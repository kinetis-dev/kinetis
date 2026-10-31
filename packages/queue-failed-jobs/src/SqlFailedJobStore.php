<?php

declare(strict_types=1);

namespace Kinetis\QueueFailedJobs;

use InvalidArgumentException;
use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Queue\FailedJob;
use Kinetis\Queue\FailedJobRecorderInterface;
use Kinetis\Queue\QueueContract;

/**
 * Failed jobs in the `kinetis_failed_jobs` table — see
 * resources/migrations/create_kinetis_failed_jobs_table.{mysql,pgsql}.php.stub.
 *
 * Application-scoped and request-neutral: the only state is the link,
 * which belongs to whoever built this store ({@see PackageBootstrap}
 * closes the one it opens). Every statement is standard SQL both engines
 * run unchanged.
 *
 * Ids are 128 random bits as 32 lowercase hex characters, generated here
 * rather than by the server, so recording needs no driver insert-id and
 * one id names one record on every engine.
 *
 * `args` holds the raw JobSerializer payload, #[Sensitive] values
 * included, because a retry reconstructs the job from it. Reading it is
 * as sensitive as reading the queue backend itself.
 */
final readonly class SqlFailedJobStore implements FailedJobRecorderInterface
{
    private const string TABLE = 'kinetis_failed_jobs';

    private const string COLUMNS = 'id, queue_connection, queue, class, args, max_attempts, attempts, '
        . 'exception_class, exception_message, failed_at';

    public function __construct(
        private SqlLink $db,
    ) {}

    /**
     * Returns once the INSERT has completed; any failure propagates, which
     * is what keeps QueueWorker from deleting a job it could not record.
     */
    #[\Override]
    public function record(FailedJob $job): string
    {
        $id = bin2hex(random_bytes(16));

        $this->db->execute(
            'INSERT INTO ' . self::TABLE . ' (' . self::COLUMNS . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $id,
                $job->connection,
                $job->queue,
                $job->class,
                // PRESERVE_ZERO_FRACTION for the reason SqlQueue gives: an
                // integral float must not come back as an int on retry.
                json_encode($job->args, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                $job->maxAttempts,
                $job->attempts,
                $job->exceptionClass,
                $job->exceptionMessage,
                $job->failedAt,
            ],
        );

        return $id;
    }

    public function find(string $id): ?StoredFailedJob
    {
        $row = $this->db
            ->execute('SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . ' WHERE id = ?', [$id])
            ->fetchRow();

        return $row === null ? null : self::fromRow($row);
    }

    /**
     * Oldest first, ordered by (failed_at, id) so two records failed in
     * the same second still have one fixed order. $after is the last
     * record of the previous page; the next page starts strictly after
     * it, so a record forgotten or added meanwhile never shifts a page.
     *
     * $connection selects records by the queue connection the worker ran:
     * false for any, null for the bound queue only, or a connection name.
     * $queue, when given, selects one queue name.
     *
     * @return list<StoredFailedJob>
     */
    public function list(
        int $limit,
        string|false|null $connection = false,
        ?string $queue = null,
        ?StoredFailedJob $after = null,
    ): array {
        if ($limit < 1) {
            throw new InvalidArgumentException("The failed-job page size must be at least 1, got {$limit}.");
        }

        $conditions = [];
        $params = [];

        if ($connection === null) {
            $conditions[] = 'queue_connection IS NULL';
        } elseif ($connection !== false) {
            QueueContract::assertValidConnectionName($connection, 'the connection filter');
            $conditions[] = 'queue_connection = ?';
            $params[] = $connection;
        }

        if ($queue !== null) {
            QueueContract::assertValidQueueName($queue);
            $conditions[] = 'queue = ?';
            $params[] = $queue;
        }

        if ($after !== null) {
            $conditions[] = '(failed_at > ? OR (failed_at = ? AND id > ?))';
            array_push($params, $after->job->failedAt, $after->job->failedAt, $after->id);
        }

        // $limit is an int checked above, inlined rather than bound: a
        // bound LIMIT is not portable across the drivers' prepare modes.
        $sql = 'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE
            . ($conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions))
            . " ORDER BY failed_at ASC, id ASC LIMIT {$limit}";

        $jobs = [];

        foreach ($this->db->execute($sql, $params) as $row) {
            /** @var array<string, mixed> $row */
            $jobs[] = self::fromRow($row);
        }

        return $jobs;
    }

    public function forget(string $id): void
    {
        $this->db->execute('DELETE FROM ' . self::TABLE . ' WHERE id = ?', [$id]);
    }

    /**
     * Deletes every record that failed more than $olderThanSeconds ago and
     * returns how many it deleted. 0 deletes every record older than the
     * current second.
     */
    public function prune(int $olderThanSeconds): int
    {
        if ($olderThanSeconds < 0) {
            throw new InvalidArgumentException("The prune age must not be negative, got {$olderThanSeconds} seconds.");
        }

        return $this->db
            ->execute('DELETE FROM ' . self::TABLE . ' WHERE failed_at < ?', [time() - $olderThanSeconds])
            ->getRowCount() ?? 0;
    }

    /**
     * Drivers return most columns as strings, so every counter goes
     * through QueueContract's decoders rather than a bare cast.
     *
     * @param array<string, mixed> $row
     */
    private static function fromRow(array $row): StoredFailedJob
    {
        $connection = $row['queue_connection'];

        return new StoredFailedJob((string) $row['id'], new FailedJob(
            $connection === null ? null : (string) $connection,
            QueueContract::storedQueueName($row['queue']),
            QueueContract::storedClass($row['class']),
            QueueContract::storedArgs(QueueContract::storedJsonArray((string) $row['args'], 'args')),
            QueueContract::storedNullableInt($row['max_attempts'], 'max_attempts', 0),
            QueueContract::storedInt($row['attempts'], 'attempts', 1),
            (string) $row['exception_class'],
            (string) $row['exception_message'],
            QueueContract::storedInt($row['failed_at'], 'failed_at', 0),
        ));
    }
}
