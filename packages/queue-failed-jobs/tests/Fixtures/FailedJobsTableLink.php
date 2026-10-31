<?php

declare(strict_types=1);

namespace Kinetis\QueueFailedJobs\Tests\Fixtures;

use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Contract\SqlTransaction;
use Kinetis\Persistence\Driver\BufferedSqlResult;
use LogicException;

/**
 * An in-memory `kinetis_failed_jobs` behind SqlLink, answering exactly
 * the statements SqlFailedJobStore issues, for command tests that need a
 * store but not a server. Values are kept as strings, as the drivers
 * return them, so the store's decoders run. Anything else it is sent
 * fails loudly. The real SQL is covered by tests/Integration.
 */
final class FailedJobsTableLink implements SqlLink
{
    private const array COLUMNS = [
        'id', 'queue_connection', 'queue', 'class', 'args', 'max_attempts', 'attempts',
        'exception_class', 'exception_message', 'failed_at',
    ];

    /** @var array<string, array<string, ?string>> */
    public array $rows = [];

    public int $closeCalls = 0;

    #[\Override]
    public function query(string $sql): SqlResult
    {
        throw new LogicException('SqlFailedJobStore never calls query().');
    }

    #[\Override]
    public function execute(string $sql, array $params = []): SqlResult
    {
        $params = array_values($params);

        if (str_starts_with($sql, 'INSERT INTO kinetis_failed_jobs')) {
            $row = array_combine(self::COLUMNS, array_map(
                static fn (mixed $value): ?string => $value === null ? null : (string) $value,
                $params,
            ));
            $this->rows[(string) $row['id']] = $row;

            return new BufferedSqlResult([], 1, null);
        }

        if (str_starts_with($sql, 'SELECT') && str_ends_with($sql, 'WHERE id = ?')) {
            $row = $this->rows[$params[0]] ?? null;

            return new BufferedSqlResult($row === null ? [] : [$row], $row === null ? 0 : 1, null);
        }

        if (str_starts_with($sql, 'SELECT')) {
            return $this->select($sql, $params);
        }

        if ($sql === 'DELETE FROM kinetis_failed_jobs WHERE id = ?') {
            $deleted = isset($this->rows[$params[0]]) ? 1 : 0;
            unset($this->rows[$params[0]]);

            return new BufferedSqlResult([], $deleted, null);
        }

        if ($sql === 'DELETE FROM kinetis_failed_jobs WHERE failed_at < ?') {
            $before = \count($this->rows);
            $this->rows = array_filter($this->rows, static fn (array $row): bool => (int) $row['failed_at'] >= $params[0]);

            return new BufferedSqlResult([], $before - \count($this->rows), null);
        }

        throw new LogicException("Unexpected statement: {$sql}");
    }

    /**
     * @param list<mixed> $params
     */
    private function select(string $sql, array $params): BufferedSqlResult
    {
        $rows = array_values($this->rows);

        if (str_contains($sql, 'queue_connection IS NULL')) {
            $rows = array_filter($rows, static fn (array $row): bool => $row['queue_connection'] === null);
        }

        if (str_contains($sql, 'queue_connection = ?')) {
            $connection = array_shift($params);
            $rows = array_filter($rows, static fn (array $row): bool => $row['queue_connection'] === $connection);
        }

        if (str_contains($sql, 'queue = ?')) {
            $queue = array_shift($params);
            $rows = array_filter($rows, static fn (array $row): bool => $row['queue'] === $queue);
        }

        if (str_contains($sql, 'failed_at > ?')) {
            [$failedAt, , $id] = $params;
            $rows = array_filter(
                $rows,
                static fn (array $row): bool => (int) $row['failed_at'] > $failedAt
                    || ((int) $row['failed_at'] === $failedAt && $row['id'] > $id),
            );
        }

        usort($rows, static fn (array $a, array $b): int => [(int) $a['failed_at'], $a['id']] <=> [(int) $b['failed_at'], $b['id']]);

        if (preg_match('/LIMIT (\d+)$/', $sql, $limit) !== 1) {
            throw new LogicException("Expected a LIMIT: {$sql}");
        }

        $rows = \array_slice($rows, 0, (int) $limit[1]);

        return new BufferedSqlResult($rows, \count($rows), null);
    }

    #[\Override]
    public function beginTransaction(): SqlTransaction
    {
        throw new LogicException('SqlFailedJobStore never opens a transaction.');
    }

    #[\Override]
    public function close(): void
    {
        ++$this->closeCalls;
    }

    #[\Override]
    public function isClosed(): bool
    {
        return $this->closeCalls > 0;
    }
}
