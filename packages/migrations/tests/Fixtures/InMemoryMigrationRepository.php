<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Tests\Fixtures;

use Kinetis\Migrations\MigrationRepositoryInterface;

/**
 * A real, array-backed MigrationRepositoryInterface — no database, no
 * mocking framework — so MigrationRunner's ordering and diffing logic is
 * tested against real applied()/highestApplied() behavior instead of
 * pre-programmed return values.
 */
final class InMemoryMigrationRepository implements MigrationRepositoryInterface
{
    /** @var list<string> */
    private array $applied = [];

    public bool $tableEnsured = false;

    public function ensureTableExists(): void
    {
        $this->tableEnsured = true;
    }

    public function applied(): array
    {
        $sorted = $this->applied;
        sort($sorted);

        return $sorted;
    }

    public function markApplied(string $migration): void
    {
        $this->applied[] = $migration;
    }

    public function markRolledBack(string $migration): void
    {
        $this->applied = array_values(array_filter(
            $this->applied,
            static fn (string $name): bool => $name !== $migration,
        ));
    }

    public function highestApplied(): ?string
    {
        $sorted = $this->applied;
        sort($sorted);

        $lastKey = array_key_last($sorted);

        return $lastKey === null ? null : $sorted[$lastKey];
    }
}
