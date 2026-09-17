<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use ArrayIterator;
use Closure;
use IteratorAggregate;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\MysqlTransaction;
use Kinetis\Persistence\Contract\SqlResult;
use LogicException;
use Traversable;

/**
 * A link that answers with rows given to it, and records the statements
 * it was asked to run — on the link itself and on every transaction it
 * began, in one list, because a unit of work reads outside its flush and
 * writes inside it.
 *
 * The repositories are `final` by convention, so they cannot be doubled;
 * substituting the connection under a real EntityManager is what makes
 * them testable without a database, and it exercises the mapping and the
 * SQL the ORM actually produces rather than replacing them with an
 * expectation.
 */
final class CannedMysqlLink implements MysqlLink
{
    /** @var list<array{string, list<mixed>}> */
    public array $statements = [];

    /** @var list<'commit'|'rollback'|'close'> how each transaction ended, in order */
    public array $ends = [];

    /** Runs after a statement is recorded and before its result returns, where a driver waits on the server. */
    public ?Closure $onStatement = null;

    /** Runs inside commit(), before it returns, where a driver waits for the server to answer it. */
    public ?Closure $onCommit = null;

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
        return $this->run($sql, []);
    }

    #[\Override]
    public function execute(string $sql, array $params = []): SqlResult
    {
        return $this->run($sql, \array_values($params));
    }

    #[\Override]
    public function beginTransaction(): MysqlTransaction
    {
        return new CannedMysqlTransaction($this);
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

    /**
     * @internal shared with the transactions this link begins
     * @param list<mixed> $params
     */
    public function run(string $sql, array $params): SqlResult
    {
        $this->statements[] = [$sql, $params];

        if ($this->onStatement !== null) {
            ($this->onStatement)();
        }

        return new CannedSqlResult($this->rows, $this->insertId, $this->affected);
    }
}

/**
 * The transaction a flush writes through. Every statement lands in the
 * link's own list, so a test reads one ordered record of the reads
 * before the flush and the writes inside it.
 */
final class CannedMysqlTransaction implements MysqlTransaction
{
    private bool $active = true;

    public function __construct(private readonly CannedMysqlLink $link) {}

    #[\Override]
    public function query(string $sql): SqlResult
    {
        return $this->link->run($sql, []);
    }

    #[\Override]
    public function execute(string $sql, array $params = []): SqlResult
    {
        return $this->link->run($sql, \array_values($params));
    }

    #[\Override]
    public function beginTransaction(): MysqlTransaction
    {
        throw new LogicException('A unit of work flushes on one transaction; it never nests another.');
    }

    #[\Override]
    public function commit(): void
    {
        $this->link->ends[] = 'commit';
        $this->active = false;

        if ($this->link->onCommit !== null) {
            ($this->link->onCommit)();
        }
    }

    #[\Override]
    public function rollback(): void
    {
        $this->link->ends[] = 'rollback';
        $this->active = false;
    }

    #[\Override]
    public function close(): void
    {
        $this->link->ends[] = 'close';
        $this->active = false;
    }

    #[\Override]
    public function isActive(): bool
    {
        return $this->active;
    }

    #[\Override]
    public function isClosed(): bool
    {
        return !$this->active;
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
