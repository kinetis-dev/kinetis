<?php

declare(strict_types=1);

namespace Kinetis\Migrations;

/**
 * Tracks which migrations have been applied. The one seam MigrationRunner
 * depends on instead of talking SQL directly, so its own ordering/diffing
 * logic (which migrations are pending, which one rollback() should target)
 * is testable against an in-memory fake, the same "swap the storage, not
 * the whole system" precedent Kinetis's own InMemorySimpleCache/
 * InMemoryLogger test fixtures already establish.
 */
interface MigrationRepositoryInterface
{
    public function ensureTableExists(): void;

    /**
     * @return list<string> every applied migration's name, ascending
     */
    public function applied(): array;

    public function markApplied(string $migration): void;

    public function markRolledBack(string $migration): void;

    /**
     * The most recently applied migration's name, or null if none has
     * been applied yet.
     */
    public function lastApplied(): ?string;
}
