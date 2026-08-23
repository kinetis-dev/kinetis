<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Tests\Fixtures;

use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Contract\SqlTransaction;
use Kinetis\Persistence\Driver\BufferedSqlResult;
use LogicException;

/**
 * Satisfies MigrationRunner's MysqlLink|PostgresLink constructor type for
 * tests that never actually touch a database — MigrationRunner only
 * forwards $db to a Migration's up()/down() and its own GET_LOCK/
 * RELEASE_LOCK calls, and the fixture migrations used in these tests
 * don't do anything with $db either. Recognizes the two lock statements
 * specifically (tracked on $lockAcquired/$lockReleased so a test can
 * assert locking actually happened); anything else throws, so a test
 * accidentally exercising real execution fails loudly instead of
 * hanging.
 */
final class FakeMysqlLink implements MysqlLink
{
    public bool $lockAcquired = false;

    public bool $lockReleased = false;

    /** Simulates GET_LOCK() timing out instead of acquiring. */
    public bool $lockAcquireShouldTimeOut = false;

    /**
     * Every execute() call's own SQL and bound params, verbatim, in
     * order — str_contains() alone (what this fake's own dispatch
     * already uses) can't tell a correct GET_LOCK(?, ?) call apart from
     * one that dropped a bound param, so a test asserting real
     * correctness has to inspect these directly rather than only the
     * boolean flags above.
     *
     * @var list<array{sql: string, params: list<mixed>}>
     */
    public array $calls = [];

    /** Returned as the acquired flag's own value — an int by default, but overridable to prove a (int) cast actually matters. */
    public mixed $acquiredValue = 1;

    public function query(string $sql): SqlResult
    {
        throw new LogicException("FakeMysqlLink does not execute queries: {$sql}");
    }

    public function execute(string $sql, array $params = []): SqlResult
    {
        $this->calls[] = ['sql' => $sql, 'params' => array_values($params)];

        if (str_contains($sql, 'GET_LOCK')) {
            if ($this->lockAcquireShouldTimeOut) {
                return new BufferedSqlResult([['acquired' => 0]], 1, 1);
            }

            $this->lockAcquired = true;

            return new BufferedSqlResult([['acquired' => $this->acquiredValue]], 1, 1);
        }

        if (str_contains($sql, 'RELEASE_LOCK')) {
            $this->lockReleased = true;

            return new BufferedSqlResult([['released' => 1]], 1, 1);
        }

        throw new LogicException("FakeMysqlLink does not execute queries: {$sql}");
    }

    public function beginTransaction(): SqlTransaction
    {
        throw new LogicException('FakeMysqlLink does not support transactions.');
    }

    public function close(): void
    {
    }

    public function isClosed(): bool
    {
        return false;
    }
}
