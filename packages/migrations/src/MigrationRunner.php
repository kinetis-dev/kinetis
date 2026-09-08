<?php

declare(strict_types=1);

namespace Kinetis\Migrations;

use InvalidArgumentException;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Migrations\Exception\MigrationIntegrityException;
use Kinetis\Migrations\Exception\MigrationLockTimeoutException;
use Throwable;

/**
 * Orchestrates migrate()/rollback()/status() against a migrations
 * directory and a MigrationRepositoryInterface.
 *
 * A migration's up()/down() is never wrapped in a transaction: Postgres
 * supports transactional DDL, MySQL's DDL statements auto-commit
 * regardless of any surrounding transaction, so a runner-imposed
 * transaction would be real atomicity on one backend and a false sense
 * of it on the other. A migration that wants atomicity on Postgres opens
 * one itself, inside its own up(). If a migration's up() throws, migrate()
 * catches nothing: every migration before it is already marked applied,
 * the failing one is not, and the exception propagates.
 *
 * migrate() and rollback() hold a cross-process advisory lock (MySQL's
 * GET_LOCK/RELEASE_LOCK, Postgres's pg_try_advisory_lock/
 * pg_advisory_unlock) for their whole duration, so two deploy instances
 * starting together cannot both compute the same pending set and run it
 * twice. Both are session-scoped rather than transaction-scoped, which is
 * what a migration's DDL needs — a transaction-held row lock would be
 * released by MySQL's implicit per-DDL commit partway through a run — and
 * both release on their own when the session closes, gracefully or not.
 *
 * Every entry point verifies the ledger against the migrations
 * directory before acting on it: each applied migration still has a file,
 * and that file still hashes to the checksum recorded when it ran. The
 * first migration failing either check throws
 * MigrationIntegrityException, before any up(), down() or ledger write —
 * a database whose applied SQL is not the SQL on disk is not one more
 * migrations can be reasoned about. migrate() and rollback() verify
 * inside the lock, pending() and status() directly.
 *
 * $db must hold one session for the whole run: a lock taken on one
 * session is not held on another. That is what
 * {@see \Kinetis\Persistence\SqlConnectionFactory::singleSession()}
 * builds, and what {@see \Kinetis\Migrations\Console\MigrationContext}
 * passes the migrate:* commands. A pooling link moves between sessions
 * per operation, and a reconnecting one replaces a session it loses —
 * either would go on running migrations with the lock gone. A
 * single-session client closes instead, so the run stops where its
 * session did.
 */
final readonly class MigrationRunner
{
    /** MySQL GET_LOCK() name. */
    private const string LOCK_NAME = 'kinetis_migrations';

    /**
     * Postgres advisory lock's two-integer key: a fixed "namespace" (an
     * arbitrary, distinctive constant) plus a second key disambiguating
     * this specific lock from any other kinetis advisory lock that might
     * ever share the namespace. Postgres advisory locks share one global
     * numeric space per database — application code taking its own
     * pg_advisory_lock() calls could collide with a plain single-integer
     * key, which this two-key form is chosen specifically to make
     * unlikely without eliminating the underlying possibility entirely.
     */
    private const int PG_LOCK_NAMESPACE = 870_124;
    private const int PG_LOCK_KEY = 1;

    public function __construct(
        private MysqlLink|PostgresLink $db,
        private MigrationRepositoryInterface $repository,
        private string $migrationsPath,
        private int $lockTimeoutSeconds = 10,
    ) {
        // Rejected here, not left to whatever a negative value happens
        // to mean to each backend: MySQL's GET_LOCK() timeout parameter
        // has backend-specific (potentially infinite-wait) semantics for
        // a negative value, while acquireLock()'s own Postgres poll loop
        // would instead treat one as an already-past deadline — two
        // different, undocumented-here behaviors for the same input is
        // worse than refusing it outright. Zero is valid: it means a
        // single immediate probe, never a wait, on both backends.
        if ($lockTimeoutSeconds < 0) {
            throw new InvalidArgumentException("\$lockTimeoutSeconds must not be negative, got {$lockTimeoutSeconds}.");
        }
    }

    /**
     * @return list<MigrationFile>
     * @throws MigrationIntegrityException
     */
    public function pending(): array
    {
        $files = MigrationFile::discover($this->migrationsPath);
        $applied = $this->verifiedApplied($files);

        return array_values(array_filter(
            $files,
            static fn (MigrationFile $file): bool => !isset($applied[$file->name]),
        ));
    }

    /**
     * @return list<string> names of the migrations actually run, in the
     *     order they ran
     * @throws MigrationIntegrityException
     */
    public function migrate(): array
    {
        return $this->withLock(function (): array {
            $files = MigrationFile::discover($this->migrationsPath);
            $applied = $this->verifiedApplied($files);
            $names = [];

            foreach ($files as $file) {
                if (isset($applied[$file->name])) {
                    continue;
                }

                // Hashed immediately before the file is loaded and run, so
                // the ledger records the source that up() executed, and
                // records it only once up() has returned: a migration that
                // throws leaves no row behind claiming it applied.
                $checksum = $file->checksum();
                $file->load()->up($this->db);
                $this->repository->markApplied($file->name, $checksum);
                $names[] = $file->name;
            }

            return $names;
        });
    }

    /**
     * Undoes the migration this database applied most recently — one
     * migration, with no batch or group concept. Run it again to reach
     * earlier ones. Application order, not name order: a migration merged
     * from another branch is applied after a later-timestamped one and is
     * the first to come back off, and a migration rolled back and applied
     * again is the newest one from then on.
     *
     * @throws MigrationIntegrityException
     */
    public function rollback(): ?string
    {
        return $this->withLock(function (): ?string {
            $files = MigrationFile::discover($this->migrationsPath);
            $applied = $this->verifiedApplied($files);
            $name = array_key_last($applied);

            if ($name === null) {
                return null;
            }

            $this->byName($files)[$name]->load()->down($this->db);
            $this->repository->markRolledBack($name);

            return $name;
        });
    }

    /**
     * Acquires the advisory lock, runs $operation, and releases the lock
     * afterwards. When $operation fails, its own failure is what
     * propagates: a release that also fails on the way out is not what
     * went wrong, and the lock releases with the session regardless.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function withLock(callable $operation): mixed
    {
        $this->acquireLock();

        try {
            $result = $operation();
        } catch (Throwable $e) {
            try {
                $this->releaseLock();
            } catch (Throwable) {
                // Subordinate to $e, which is what the caller needs.
            }

            throw $e;
        }

        $this->releaseLock();

        return $result;
    }

    /**
     * @return list<array{name: string, applied: bool}>
     * @throws MigrationIntegrityException
     */
    public function status(): array
    {
        $files = MigrationFile::discover($this->migrationsPath);
        $applied = $this->verifiedApplied($files);

        return array_map(
            static fn (MigrationFile $file): array => ['name' => $file->name, 'applied' => isset($applied[$file->name])],
            $files,
        );
    }

    /**
     * The ledger, verified against $files before its caller acts on it.
     * Discovery belongs to the caller so that one listing serves both the
     * check and the work that follows it.
     *
     * @param list<MigrationFile> $files
     * @return array<string, string> name => recorded checksum, in
     *     application order
     * @throws MigrationIntegrityException
     */
    private function verifiedApplied(array $files): array
    {
        $this->repository->ensureTableExists();
        $applied = $this->repository->applied();
        $byName = $this->byName($files);

        foreach ($applied as $name => $checksum) {
            $file = $byName[$name] ?? throw MigrationIntegrityException::forMissingSource($name);

            if ($file->checksum() !== $checksum) {
                throw MigrationIntegrityException::forChecksumMismatch($name);
            }
        }

        return $applied;
    }

    /**
     * @param list<MigrationFile> $files
     * @return array<string, MigrationFile>
     */
    private function byName(array $files): array
    {
        $byName = [];

        foreach ($files as $file) {
            $byName[$file->name] = $file;
        }

        return $byName;
    }

    private function acquireLock(): void
    {
        if ($this->db instanceof MysqlLink) {
            $result = $this->db->execute('SELECT GET_LOCK(?, ?) AS acquired', [self::LOCK_NAME, $this->lockTimeoutSeconds]);
            $acquired = $result->fetchRow()['acquired'] ?? null;

            if ((int) $acquired !== 1) {
                throw MigrationLockTimeoutException::forTimeout($this->lockTimeoutSeconds);
            }

            return;
        }

        // Postgres has no timeout parameter on its own advisory-lock
        // functions, unlike MySQL's GET_LOCK — pg_try_advisory_lock is
        // the non-blocking primitive, polled with a short sleep between
        // attempts, the same "no native blocking-with-timeout" shape
        // Kinetis\QueueSql\SqlQueue::pop() already uses. The ::int cast
        // makes the answer a plain 0/1 on both drivers, which represent
        // a SQL boolean differently.
        $deadline = microtime(true) + $this->lockTimeoutSeconds;

        while (true) {
            $result = $this->db->query(
                'SELECT pg_try_advisory_lock(' . self::PG_LOCK_NAMESPACE . ', ' . self::PG_LOCK_KEY . ')::int AS acquired',
            );

            if ((int) ($result->fetchRow()['acquired'] ?? 0) === 1) {
                return;
            }

            if (microtime(true) >= $deadline) {
                throw MigrationLockTimeoutException::forTimeout($this->lockTimeoutSeconds);
            }

            usleep(100_000);
        }
    }

    private function releaseLock(): void
    {
        if ($this->db instanceof MysqlLink) {
            $this->db->execute('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]);

            return;
        }

        $this->db->query(
            'SELECT pg_advisory_unlock(' . self::PG_LOCK_NAMESPACE . ', ' . self::PG_LOCK_KEY . ')',
        );
    }
}
