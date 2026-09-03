<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests\Fixtures;

use ArrayIterator;
use IteratorAggregate;
use Kinetis\Persistence\Contract\SqlResult;
use Traversable;

/**
 * A single scripted SqlResult — an optional row (returned once by
 * fetchRow(), then exhausted, matching the real "next unconsumed row"
 * contract) and an independently-settable row count, since a real
 * UPDATE/INSERT's affected-row count and a SELECT's returned row are
 * two genuinely different signals a test needs to control separately
 * (a driver can report null/0 for the former while a row still exists).
 *
 * @internal test fixture only
 */
final class FakeSqlRowResult implements SqlResult, IteratorAggregate
{
    private bool $consumed = false;

    /**
     * @param ?array<string, mixed> $row
     */
    public function __construct(
        private readonly ?array $row = null,
        private readonly ?int $rowCount = null,
    ) {
    }

    #[\Override]
    public function fetchRow(): ?array
    {
        if ($this->consumed) {
            return null;
        }

        $this->consumed = true;

        return $this->row;
    }

    #[\Override]
    public function getRowCount(): ?int
    {
        return $this->rowCount;
    }

    #[\Override]
    public function getColumnCount(): ?int
    {
        return $this->row === null ? 0 : \count($this->row);
    }

    #[\Override]
    public function getLastInsertId(): ?int
    {
        return null;
    }

    #[\Override]
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->row === null ? [] : [$this->row]);
    }
}
