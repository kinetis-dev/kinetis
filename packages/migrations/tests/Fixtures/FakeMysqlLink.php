<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Tests\Fixtures;

use Amp\Mysql\MysqlLink;
use Amp\Mysql\MysqlResult;
use Amp\Mysql\MysqlStatement;
use Amp\Mysql\MysqlTransaction;
use LogicException;

/**
 * Satisfies MigrationRunner's MysqlLink|PostgresLink constructor type for
 * tests that never actually query the database — MigrationRunner only
 * forwards $db to a Migration's up()/down(), and the fixture migrations
 * used in these tests don't touch it either. Every method that would need
 * a real connection throws, so a test accidentally exercising execution
 * fails loudly instead of hanging.
 */
final class FakeMysqlLink implements MysqlLink
{
    public function query(string $sql): MysqlResult
    {
        throw new LogicException('FakeMysqlLink does not execute queries.');
    }

    public function prepare(string $sql): MysqlStatement
    {
        throw new LogicException('FakeMysqlLink does not execute queries.');
    }

    public function execute(string $sql, array $params = []): MysqlResult
    {
        throw new LogicException('FakeMysqlLink does not execute queries.');
    }

    public function beginTransaction(): MysqlTransaction
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

    public function onClose(\Closure $onClose): void
    {
    }

    public function getLastUsedAt(): int
    {
        return 0;
    }
}
