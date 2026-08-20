<?php

declare(strict_types=1);

namespace Kinetis\Migrations;

use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Migrations\Exception\MigrationFileMissingException;
use Kinetis\Migrations\Exception\MigrationLockTimeoutException;

/**
 * Orchestrates migrate()/rollback()/status() against a migrations
 * directory and a MigrationRepositoryInterface. Never wraps a migration's
 * up()/down() in a transaction: Postgres supports transactional DDL,
 * MySQL's DDL statements auto-commit regardless of any surrounding
 * transaction, so a runner-imposed transaction would be real atomicity on
 * one backend and a false sense of it on the other. A migration that
 * wants atomicity on Postgres opens one itself, inside its own up() —
 * which is also why migrate()/rollback() never wrap the lock below in a
 * beginTransaction() of their own: a still-open transaction on the same
 * connection would either make that migration-level beginTransaction()
 * throw (this project's drivers reject nested transactions outright) or,
 * on the PDO drivers specifically, silently wrap the migration's own DDL
 * in it — the exact false atomicity this class exists to avoid.
 *
 * If a migration's up() throws mid-run, migrate() doesn't catch it or roll
 * anything back — every migration before it in this call is already
 * marked applied (correctly: they succeeded), the failing one is not
 * (correctly: it didn't complete), and the exception propagates so the
 * caller sees a real failure instead of a silently partial run.
 *
 * migrate() and rollback() hold a cross-process advisory lock for their
 * whole duration, so two deploy instances starting together can't both
 * compute the same pending set and run it twice. A unique row in
 * MigrationRepositoryInterface's own table only makes the second run's
 * final markApplied() fail — by then its up() has already executed.
 * Advisory locks (MySQL's GET_LOCK/RELEASE_LOCK, Postgres's
 * pg_try_advisory_lock/pg_advisory_unlock) are the portable primitive
 * here, not a transactional row lock: they're session-scoped rather than
 * transaction-scoped, which is what a migration's own DDL needs — a
 * transaction-held row lock would be silently released by MySQL's
 * implicit per-DDL commit partway through a real migration run. Session
 * scope also answers "what happens to an abandoned lock": both
 * mechanisms release automatically the moment the session/connection
 * that holds them closes, gracefully or not, with no separate cleanup.
 *
 * Both lock calls are issued directly on the injected $db, not through a
 * dedicated beginTransaction() — deliberately, to avoid the nesting
 * conflict above. This makes session continuity between the acquire and
 * release call exact under $db's normal, realistic shape for this class
 * (SqlConnectionFactory's PDO drivers, a single physical connection for
 * the object's whole lifetime — which is what a one-shot CLI migration
 * run resolves to under DB_DRIVER=auto outside a persistent worker) and
 * under a native driver pool sized to one connection. A disclosed,
 * narrower gap remains for a native driver pool sized above one
 * connection: the acquire and release calls are not guaranteed to reuse
 * the same pooled physical connection, so the release can land on a
 * connection that never held the lock — the lock itself still expires
 * safely on its own once the connection that actually holds it closes,
 * but not necessarily as promptly as an explicit release. Not a concern
 * for the realistic case this class is built for: migrations have no use
 * for concurrent connections, so DB_DRIVER=native with maxConnections > 1
 * for a migration run specifically is not a configuration this project
 * has any reason to recommend.
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
        $this->acquireLock();

        try {
            $names = [];

            foreach ($this->pending() as $file) {
                $file->load()->up($this->db);
                $this->repository->markApplied($file->name);
                $names[] = $file->name;
            }

            return $names;
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Rolls back the single most recently applied migration only — there
     * is no batch/group concept here, unlike some migration tools.
     *
     * @throws MigrationFileMissingException
     */
    public function rollback(): ?string
    {
        $this->acquireLock();

        try {
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
        } finally {
            $this->releaseLock();
        }
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
        // Kinetis\QueueSql\SqlQueue::pop() already uses for the identical
        // reason. Cast to ::int so the result is a plain 0/1 regardless
        // of whether the driver represents SQL boolean as a native PHP
        // bool or Postgres's own "t"/"f" text — confirmed to differ
        // between the native and PDO drivers, not assumed.
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
        } else {
            $this->db->query('SELECT pg_advisory_unlock(' . self::PG_LOCK_NAMESPACE . ', ' . self::PG_LOCK_KEY . ')');
        }
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
