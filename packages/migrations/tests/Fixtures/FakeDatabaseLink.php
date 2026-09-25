<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Tests\Fixtures;

use Closure;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Contract\SqlTransaction;
use Kinetis\Persistence\Driver\BufferedSqlResult;
use LogicException;

/**
 * A session on a FakeDatabase that answers exactly the statements
 * MigrationRunner and SqlMigrationRepository send — the advisory lock
 * and the kinetis_migrations ledger — and records anything else, which
 * is what a migration's up()/down() ran. Refuses every statement once
 * closed, and reports its close to $onClose once.
 */
final class FakeDatabaseLink implements MysqlLink
{
    private bool $closed = false;

    /** @param Closure(): void $onClose */
    public function __construct(
        private readonly FakeDatabase $database,
        private readonly Closure $onClose,
    ) {}

    public function query(string $sql): SqlResult
    {
        return $this->execute($sql);
    }

    public function execute(string $sql, array $params = []): SqlResult
    {
        if ($this->closed) {
            throw new LogicException("Executed on a closed link: {$sql}");
        }

        if (str_contains($sql, 'GET_LOCK')) {
            return new BufferedSqlResult([['acquired' => 1]], 1, 1);
        }

        if (str_starts_with($sql, 'CREATE TABLE IF NOT EXISTS kinetis_migrations') || str_contains($sql, 'RELEASE_LOCK')) {
            return new BufferedSqlResult([], 0, null);
        }

        if (str_starts_with($sql, 'SELECT migration, checksum FROM kinetis_migrations')) {
            $rows = [];

            foreach ($this->database->ledger as $migration => $checksum) {
                $rows[] = ['migration' => $migration, 'checksum' => $checksum];
            }

            return new BufferedSqlResult($rows, \count($rows), 2);
        }

        if (str_starts_with($sql, 'INSERT INTO kinetis_migrations')) {
            $this->database->ledger[(string) $params[0]] = (string) $params[1];

            return new BufferedSqlResult([], 1, null);
        }

        if (str_starts_with($sql, 'DELETE FROM kinetis_migrations')) {
            unset($this->database->ledger[(string) $params[0]]);

            return new BufferedSqlResult([], 1, null);
        }

        $this->database->executed[] = $sql;

        return new BufferedSqlResult([], 0, null);
    }

    public function beginTransaction(): SqlTransaction
    {
        throw new LogicException('FakeDatabaseLink does not support transactions.');
    }

    public function close(): void
    {
        if (!$this->closed) {
            $this->closed = true;
            ($this->onClose)();
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }
}
