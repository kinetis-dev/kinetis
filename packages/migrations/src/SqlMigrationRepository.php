<?php

declare(strict_types=1);

namespace Kinetis\Migrations;

use Amp\Sql\SqlLink;
use DateTimeImmutable;

/**
 * Typed against the generic Amp\Sql\SqlLink, not MysqlLink|PostgresLink —
 * unlike MigrationRunner (which forwards $db to a Migration's own
 * dialect-typed up()/down()), this class only ever issues its own
 * bookkeeping SQL, and every statement below (CREATE TABLE IF NOT EXISTS,
 * a VARCHAR primary key, a TIMESTAMP column, parameterized SELECT/INSERT/
 * DELETE) is standard SQL that runs identically on MySQL and Postgres, so
 * there's no dialect to detect here at all.
 */
final class SqlMigrationRepository implements MigrationRepositoryInterface
{
    private const TABLE = 'kinetis_migrations';

    /**
     * @param SqlLink<*, *, *> $db
     */
    public function __construct(
        private readonly SqlLink $db,
    ) {}

    #[\Override]
    public function ensureTableExists(): void
    {
        $this->db->execute(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
            . 'migration VARCHAR(255) NOT NULL PRIMARY KEY, '
            . 'applied_at TIMESTAMP NOT NULL'
            . ')',
        );
    }

    #[\Override]
    public function applied(): array
    {
        $result = $this->db->execute('SELECT migration FROM ' . self::TABLE . ' ORDER BY migration ASC');

        $names = [];

        foreach ($result as $row) {
            $names[] = (string) $row['migration'];
        }

        return $names;
    }

    #[\Override]
    public function markApplied(string $migration): void
    {
        $this->db->execute(
            'INSERT INTO ' . self::TABLE . ' (migration, applied_at) VALUES (?, ?)',
            [$migration, (new DateTimeImmutable())->format('Y-m-d H:i:s')],
        );
    }

    #[\Override]
    public function markRolledBack(string $migration): void
    {
        $this->db->execute('DELETE FROM ' . self::TABLE . ' WHERE migration = ?', [$migration]);
    }

    #[\Override]
    public function lastApplied(): ?string
    {
        $result = $this->db->execute('SELECT migration FROM ' . self::TABLE . ' ORDER BY migration DESC LIMIT 1');
        $row = $result->fetchRow();

        return $row !== null ? (string) $row['migration'] : null;
    }
}
