<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Tests\Fixtures;

use Kinetis\Migrations\MigrationRepositoryInterface;

/**
 * A real, array-backed MigrationRepositoryInterface — no database, no
 * mocking framework — so MigrationRunner's ordering, diffing and
 * integrity logic is tested against real applied() behavior instead of
 * pre-programmed return values. Insertion order stands in for
 * SqlMigrationRepository's application_order column, down to a migration
 * recorded again after a rollback landing last.
 */
final class InMemoryMigrationRepository implements MigrationRepositoryInterface
{
    /** @var array<string, string> */
    private array $applied = [];

    public bool $tableEnsured = false;

    public function ensureTableExists(): void
    {
        $this->tableEnsured = true;
    }

    public function applied(): array
    {
        return $this->applied;
    }

    public function markApplied(string $migration, string $checksum): void
    {
        unset($this->applied[$migration]);

        $this->applied[$migration] = $checksum;
    }

    public function markRolledBack(string $migration): void
    {
        unset($this->applied[$migration]);
    }
}
