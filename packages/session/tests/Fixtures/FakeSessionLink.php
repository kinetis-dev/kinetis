<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests\Fixtures;

use ArrayIterator;
use IteratorAggregate;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Contract\SqlTransaction;
use Kinetis\Session\Exception\SessionException;
use Traversable;

/** Enough of a MysqlLink for the bootstrap's binding test. */
final class FakeSessionLink implements MysqlLink
{
    #[\Override]
    public function query(string $sql): SqlResult
    {
        return self::emptyResult();
    }

    /**
     * @param list<mixed> $params
     */
    #[\Override]
    public function execute(string $sql, array $params = []): SqlResult
    {
        return self::emptyResult();
    }

    #[\Override]
    public function beginTransaction(): SqlTransaction
    {
        throw new SessionException('Not needed by this fixture.');
    }

    #[\Override]
    public function close(): void {}

    #[\Override]
    public function isClosed(): bool
    {
        return false;
    }

    private static function emptyResult(): SqlResult
    {
        return new class implements SqlResult, IteratorAggregate {
            #[\Override]
            public function getIterator(): Traversable
            {
                return new ArrayIterator([]);
            }

            /**
             * @return ?array<string, mixed>
             */
            #[\Override]
            public function fetchRow(): ?array
            {
                return null;
            }

            #[\Override]
            public function getRowCount(): ?int
            {
                return 0;
            }

            #[\Override]
            public function getColumnCount(): ?int
            {
                return 0;
            }

            #[\Override]
            public function getLastInsertId(): ?int
            {
                return null;
            }
        };
    }
}
