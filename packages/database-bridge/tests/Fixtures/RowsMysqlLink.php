<?php

declare(strict_types=1);

namespace Kinetis\DatabaseBridge\Tests\Fixtures;

use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Contract\SqlTransaction;
use Kinetis\Persistence\Driver\BufferedSqlResult;
use LogicException;

/** Records every statement and answers each with the same fixed rows. */
final class RowsMysqlLink implements MysqlLink
{
    /** @var list<string> */
    public array $statements = [];

    public int $closeCalls = 0;

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(private readonly array $rows = []) {}

    public function query(string $sql): SqlResult
    {
        $this->statements[] = $sql;

        return new BufferedSqlResult($this->rows, count($this->rows), null);
    }

    public function execute(string $sql, array $params = []): SqlResult
    {
        return $this->query($sql);
    }

    public function beginTransaction(): SqlTransaction
    {
        throw new LogicException('Not needed by the ORM wiring.');
    }

    public function close(): void
    {
        $this->closeCalls++;
    }

    public function isClosed(): bool
    {
        return false;
    }
}
