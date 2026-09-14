<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Closure;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Contract\SqlTransaction;
use Kinetis\Persistence\Driver\BufferedSqlResult;
use LogicException;

/**
 * Records every statement and answers each with the next queued row list,
 * or no rows once the queue is empty. A trait so the MySQL and Postgres spies
 * stay final and differ only by their dialect marker.
 */
trait RecordsCalls
{
    /** @var list<array{sql: string, params: list<mixed>}> */
    public array $calls = [];

    public int $closeCalls = 0;

    /** Runs after a statement is recorded and before its result returns, as a driver suspends there. */
    public ?Closure $onStatement = null;

    /** @var list<list<array<string, mixed>>> */
    private array $results = [];

    /**
     * @param list<array<string, mixed>> ...$results one row list per statement, in order
     */
    public function queue(array ...$results): static
    {
        array_push($this->results, ...$results);

        return $this;
    }

    public function query(string $sql): SqlResult
    {
        $this->calls[] = ['sql' => $sql, 'params' => []];

        return $this->next();
    }

    /**
     * @param array<array-key, mixed> $params
     */
    public function execute(string $sql, array $params = []): SqlResult
    {
        $this->calls[] = ['sql' => $sql, 'params' => array_values($params)];

        return $this->next();
    }

    public function beginTransaction(): SqlTransaction
    {
        throw new LogicException(static::class . ' does not support transactions.');
    }

    public function close(): void
    {
        $this->closeCalls++;
    }

    public function isClosed(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    public function statements(): array
    {
        return array_column($this->calls, 'sql');
    }

    private function next(): SqlResult
    {
        if ($this->onStatement !== null) {
            ($this->onStatement)();
        }

        $rows = array_shift($this->results) ?? [];

        return new BufferedSqlResult($rows, count($rows), null);
    }
}
