<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use ArrayIterator;
use IteratorAggregate;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Contract\SqlTransaction;
use LogicException;
use Traversable;

/**
 * A link that answers with rows given to it, and records the statements
 * it was asked to run.
 *
 * The repositories are `final` by convention, so they cannot be doubled;
 * substituting the connection underneath one is what makes them testable
 * without a database, and it exercises the repository's own SQL rather
 * than replacing it with an expectation.
 */
final class CannedMysqlLink implements MysqlLink
{
    /** @var list<array{string, list<mixed>}> */
    public array $statements = [];

    /**
     * @param list<array<string, mixed>> $rows returned by every read
     */
    public function __construct(
        private readonly array $rows = [],
        private readonly ?int $insertId = 1,
        private readonly int $affected = 1,
    ) {}

    #[\Override]
    public function query(string $sql): SqlResult
    {
        $this->statements[] = [$sql, []];

        return new CannedSqlResult($this->rows, $this->insertId, $this->affected);
    }

    #[\Override]
    public function execute(string $sql, array $params = []): SqlResult
    {
        $this->statements[] = [$sql, \array_values($params)];

        return new CannedSqlResult($this->rows, $this->insertId, $this->affected);
    }

    #[\Override]
    public function beginTransaction(): SqlTransaction
    {
        throw new LogicException('CannedMysqlLink does not support transactions.');
    }

    #[\Override]
    public function close(): void
    {
    }

    #[\Override]
    public function isClosed(): bool
    {
        return false;
    }
}

/**
 * @implements IteratorAggregate<int, array<string, mixed>>
 */
final class CannedSqlResult implements SqlResult, IteratorAggregate
{
    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(
        private readonly array $rows,
        private readonly ?int $insertId,
        private readonly int $affected,
    ) {}

    #[\Override]
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->rows);
    }

    #[\Override]
    public function fetchRow(): ?array
    {
        return $this->rows[0] ?? null;
    }

    #[\Override]
    public function getRowCount(): ?int
    {
        return $this->rows === [] ? $this->affected : count($this->rows);
    }

    #[\Override]
    public function getColumnCount(): ?int
    {
        return $this->rows === [] ? 0 : count($this->rows[0]);
    }

    #[\Override]
    public function getLastInsertId(): ?int
    {
        return $this->insertId;
    }
}
