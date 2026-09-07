<?php

declare(strict_types=1);

namespace Kinetis\Migrations;

use Kinetis\Persistence\Contract\SqlLink;

/**
 * Typed against the generic Kinetis\Persistence\Contract\SqlLink, not
 * MysqlLink|PostgresLink —
 * unlike MigrationRunner (which forwards $db to a Migration's own
 * dialect-typed up()/down()), this class only ever issues its own
 * bookkeeping SQL, and every statement below (CREATE TABLE IF NOT EXISTS,
 * a VARCHAR primary key, a CHAR column, a parameterized SELECT/INSERT/
 * DELETE) is standard SQL that runs identically on MySQL and Postgres, so
 * there's no dialect to detect here at all.
 */
final class SqlMigrationRepository implements MigrationRepositoryInterface
{
    private const TABLE = 'kinetis_migrations';

    /**
     * @param SqlLink $db
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
            . 'checksum CHAR(64) NOT NULL, '
            . 'application_order INT NOT NULL UNIQUE'
            . ')',
        );
    }

    #[\Override]
    public function applied(): array
    {
        $result = $this->db->execute(
            'SELECT migration, checksum FROM ' . self::TABLE . ' ORDER BY application_order ASC',
        );

        $applied = [];

        foreach ($result as $row) {
            $applied[(string) $row['migration']] = (string) $row['checksum'];
        }

        return $applied;
    }

    /**
     * The order column is assigned from the table's own current maximum
     * in the same statement that inserts the row, which every backend
     * this package supports compiles the same way — an auto-increment or
     * an identity would each need its own dialect. MigrationRunner holds
     * the advisory lock across this call, so no second writer is racing
     * for the same number; the UNIQUE constraint is what turns a run that
     * reached here without the lock into a failed INSERT rather than two
     * rows claiming one position.
     */
    #[\Override]
    public function markApplied(string $migration, string $checksum): void
    {
        $this->db->execute(
            'INSERT INTO ' . self::TABLE . ' (migration, checksum, application_order) '
            . 'SELECT ?, ?, COALESCE(MAX(application_order), 0) + 1 FROM ' . self::TABLE,
            [$migration, $checksum],
        );
    }

    #[\Override]
    public function markRolledBack(string $migration): void
    {
        $this->db->execute('DELETE FROM ' . self::TABLE . ' WHERE migration = ?', [$migration]);
    }
}
