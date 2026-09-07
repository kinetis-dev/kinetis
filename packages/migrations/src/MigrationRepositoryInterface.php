<?php

declare(strict_types=1);

namespace Kinetis\Migrations;

/**
 * The ledger of applied migrations. The one seam MigrationRunner depends
 * on instead of talking SQL directly, so its own ordering, diffing and
 * integrity logic (which migrations are pending, which one rollback()
 * undoes, whether an applied migration's file still matches) is testable
 * against an in-memory fake, the same "swap the storage, not the whole
 * system" precedent Kinetis's own InMemorySimpleCache/InMemoryLogger test
 * fixtures already establish.
 */
interface MigrationRepositoryInterface
{
    public function ensureTableExists(): void;

    /**
     * Every applied migration's name mapped to the checksum recorded for
     * it, ordered by when this database applied it — oldest first, so the
     * last entry is the one rollback() undoes.
     *
     * @return array<string, string>
     */
    public function applied(): array;

    /**
     * Records $migration as applied, after its own up() has run, with the
     * checksum of the file that ran.
     */
    public function markApplied(string $migration, string $checksum): void;

    public function markRolledBack(string $migration): void;
}
