<?php

declare(strict_types=1);

namespace Kinetis\Migrations;

use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Migrations\Exception\MigrationFileMissingException;

/**
 * Orchestrates migrate()/rollback()/status() against a migrations
 * directory and a MigrationRepositoryInterface. Never wraps a migration's
 * up()/down() in a transaction: Postgres supports transactional DDL,
 * MySQL's DDL statements auto-commit regardless of any surrounding
 * transaction, so a runner-imposed transaction would be real atomicity on
 * one backend and a false sense of it on the other. A migration that
 * wants atomicity on Postgres opens one itself, inside its own up().
 *
 * If a migration's up() throws mid-run, migrate() doesn't catch it or roll
 * anything back — every migration before it in this call is already
 * marked applied (correctly: they succeeded), the failing one is not
 * (correctly: it didn't complete), and the exception propagates so the
 * caller sees a real failure instead of a silently partial run.
 */
final readonly class MigrationRunner
{
    public function __construct(
        private MysqlLink|PostgresLink $db,
        private MigrationRepositoryInterface $repository,
        private string $migrationsPath,
    ) {}

    /**
     * @return list<MigrationFile>
     */
    public function pending(): array
    {
        $this->repository->ensureTableExists();
        $applied = $this->repository->applied();

        return array_values(array_filter(
            MigrationFile::discover($this->migrationsPath),
            fn (MigrationFile $file): bool => !in_array($file->name, $applied, true),
        ));
    }

    /**
     * @return list<string> names of the migrations actually run, in the
     *     order they ran
     */
    public function migrate(): array
    {
        $names = [];

        foreach ($this->pending() as $file) {
            $file->load()->up($this->db);
            $this->repository->markApplied($file->name);
            $names[] = $file->name;
        }

        return $names;
    }

    /**
     * Rolls back the single most recently applied migration only — there
     * is no batch/group concept here, unlike some migration tools.
     *
     * @throws MigrationFileMissingException
     */
    public function rollback(): ?string
    {
        $this->repository->ensureTableExists();
        $name = $this->repository->lastApplied();

        if ($name === null) {
            return null;
        }

        $file = $this->findFile($name);

        if ($file === null) {
            throw MigrationFileMissingException::forName($name);
        }

        $file->load()->down($this->db);
        $this->repository->markRolledBack($name);

        return $name;
    }

    /**
     * @return list<array{name: string, applied: bool}>
     */
    public function status(): array
    {
        $this->repository->ensureTableExists();
        $applied = $this->repository->applied();

        return array_map(
            static fn (MigrationFile $file): array => ['name' => $file->name, 'applied' => in_array($file->name, $applied, true)],
            MigrationFile::discover($this->migrationsPath),
        );
    }

    private function findFile(string $name): ?MigrationFile
    {
        foreach (MigrationFile::discover($this->migrationsPath) as $file) {
            if ($file->name === $name) {
                return $file;
            }
        }

        return null;
    }
}
