<?php

declare(strict_types=1);

namespace Kinetis\Migrations;

use InvalidArgumentException;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Migrations\Exception\MigrationFileMissingException;
use Kinetis\Migrations\Exception\MigrationLockReleaseException;
use Kinetis\Migrations\Exception\MigrationLockTimeoutException;
use Throwable;

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
 * release call exact only when $db itself resolves to a single physical
 * connection for its whole lifetime — a PDO driver always does (one
 * blocking connection), and a native driver pool does too only when
 * sized to exactly one connection. A native driver pool sized above one
 * connection breaks this: the acquire and release calls are not
 * guaranteed to reuse the same pooled physical connection, so the
 * release can land on a connection that never held the lock (the lock
 * itself still expires safely on its own once the connection that
 * actually holds it closes, but not necessarily as promptly as an
 * explicit release).
 *
 * This class cannot enforce that invariant itself — it accepts whatever
 * MysqlLink|PostgresLink its caller hands it. The migrate:* commands
 * enforce it at the one place that can, before this class ever sees
 * $db: {@see \Kinetis\Migrations\Console\MigrationContext::connection()}
 * forces maxConnections: 1 unconditionally, regardless of
 * DB_MAX_CONNECTIONS, since these commands are strictly serial and gain
 * nothing from a wider pool. A caller constructing MigrationRunner
 * directly, outside that command path, is responsible for the identical
 * guarantee itself: a link it knows resolves to one physical connection
 * for this object's whole lifetime.
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
        return $this->withLock(function (): array {
            $names = [];

            foreach ($this->pending() as $file) {
                $file->load()->up($this->db);
                $this->repository->markApplied($file->name);
                $names[] = $file->name;
            }

            return $names;
        });
    }

    /**
     * Rolls back the single most recently applied migration only — there
     * is no batch/group concept here, unlike some migration tools.
     *
     * @throws MigrationFileMissingException
     */
    public function rollback(): ?string
    {
        return $this->withLock(function (): ?string {
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
        });
    }

    /**
     * Acquires the advisory lock, runs $operation, and always releases
     * it afterward — including when releaseLock() itself fails, in
     * which case that failure is absorbed rather than allowed to take
     * $operation's own already-in-flight failure's place. PHP does not
     * discard an exception a try was already propagating when its own
     * finally throws a different one — it makes the finally's exception
     * the new outer exception and chains the original beneath it as
     * previous. Left unhandled, that means a release failure would
     * become the direct, reported cause instead of $operation's own
     * real failure, which would only be reachable one level deeper via
     * getPrevious() — the identical masking hazard
     * Kinetis\Storage\AmpFileAdapter::readMimeTypeSample() already
     * discloses and guards against for the structurally identical
     * reason (there: a close() failure racing a read() failure; here: a
     * releaseLock() failure racing $operation's own). Absorbing the
     * release failure here keeps $operation's own failure as the one
     * this method directly reports. A release failure with no operation
     * failure in flight is not absorbed — it propagates normally, since
     * releasing genuinely is part of what this method promises to do,
     * not an optional afterthought.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function withLock(callable $operation): mixed
    {
        $this->acquireLock();
        $primaryFailure = null;

        try {
            return $operation();
        } catch (Throwable $e) {
            $primaryFailure = $e;

            throw $e;
        } finally {
            try {
                $this->releaseLock();
            } catch (Throwable $releaseFailure) {
                if ($primaryFailure === null) {
                    throw $releaseFailure;
                }
            }
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

    /**
     * Checks the release call's own returned value — MySQL's
     * RELEASE_LOCK() returns 0 (a different session holds the lock) or
     * NULL (never acquired at all), Postgres's pg_advisory_unlock()
     * returns false for the equivalent case — neither backend throws
     * for this on its own, so a successful query is not the same
     * guarantee as a successful release. See MigrationLockReleaseException's
     * own docblock for what a failure here most likely means.
     */
    private function releaseLock(): void
    {
        if ($this->db instanceof MysqlLink) {
            $result = $this->db->execute('SELECT RELEASE_LOCK(?) AS released', [self::LOCK_NAME]);
            $released = $result->fetchRow()['released'] ?? null;

            if ((int) $released !== 1) {
                throw MigrationLockReleaseException::didNotRelease();
            }

            return;
        }

        $result = $this->db->query(
            'SELECT pg_advisory_unlock(' . self::PG_LOCK_NAMESPACE . ', ' . self::PG_LOCK_KEY . ')::int AS released',
        );

        if ((int) ($result->fetchRow()['released'] ?? 0) !== 1) {
            throw MigrationLockReleaseException::didNotRelease();
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
