<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Tests\Fixtures;

use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Contract\SqlTransaction;
use Kinetis\Persistence\Driver\BufferedSqlResult;
use LogicException;

/**
 * The Postgres counterpart to {@see FakeMysqlLink} — recognizes
 * MigrationRunner's pg_try_advisory_lock/pg_advisory_unlock calls
 * specifically, exercising its poll-loop/timeout path without a live
 * database. $acquiresAfterAttempts lets a test control exactly how many
 * failed poll attempts happen before a lock succeeds, or force a timeout
 * outright by leaving it higher than the runner's own configured
 * timeout will allow attempts for.
 */
final class FakePostgresLink implements PostgresLink
{
    private int $tryAttempts = 0;

    public bool $lockReleased = false;

    /**
     * Every query() call's own SQL, verbatim, in order — str_contains()
     * alone (what this fake's own dispatch already uses) can't tell a
     * correct "pg_try_advisory_lock(870124, 1)" call apart from one with
     * a wrong/dropped namespace or key value, so a test asserting real
     * correctness has to inspect these directly.
     *
     * @var list<string>
     */
    public array $calls = [];

    /** Returned as the acquired flag's own value once a poll succeeds — an int by default, but overridable to prove a (int) cast actually matters. */
    public mixed $acquiredValue = 1;

    public function __construct(
        private readonly int $acquiresAfterAttempts = 1,
    ) {
    }

    public function query(string $sql): SqlResult
    {
        $this->calls[] = $sql;

        if (str_contains($sql, 'pg_try_advisory_lock')) {
            $this->tryAttempts++;

            return new BufferedSqlResult([['acquired' => $this->tryAttempts >= $this->acquiresAfterAttempts ? $this->acquiredValue : 0]], 1, 1);
        }

        if (str_contains($sql, 'pg_advisory_unlock')) {
            $this->lockReleased = true;

            return new BufferedSqlResult([['released' => 1]], 1, 1);
        }

        throw new LogicException("FakePostgresLink does not execute queries: {$sql}");
    }

    public function execute(string $sql, array $params = []): SqlResult
    {
        throw new LogicException("FakePostgresLink does not execute queries: {$sql}");
    }

    public function beginTransaction(): SqlTransaction
    {
        throw new LogicException('FakePostgresLink does not support transactions.');
    }

    public function close(): void
    {
    }

    public function isClosed(): bool
    {
        return false;
    }
}
