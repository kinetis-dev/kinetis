<?php

declare(strict_types=1);

namespace Kinetis\Persistence;

use Kinetis\Persistence\Exception\InvalidPoolConfigurationException;
use Kinetis\Persistence\Exception\InvalidPoolReleaseException;
use Kinetis\Persistence\Exception\PoolDisposalFailedException;
use Kinetis\Persistence\Exception\PoolExhaustedException;
use Closure;
use SplObjectStorage;
use Throwable;

/**
 * A generic, protocol-agnostic connection pool: holds up to $maxSize
 * connections built by $factory, health-checks them via $isHealthy before
 * handing one out, and discards (rather than hands out) any that fail the
 * check — a connection recycled across worker-loop iterations that's
 * silently gone dead is exactly the failure mode a persistent-worker
 * framework has to guard against that boot-and-die never had to.
 *
 * Every member is owned by exactly one of two places at a time: this
 * pool's own idle list, or whichever single caller currently holds it
 * checked out — tracked explicitly via $members, not inferred from
 * $idle's own contents. That is what lets release() reject a connection
 * this pool never created, and reject releasing the same checked-out
 * member twice (or releasing one that is already idle) — both would
 * otherwise let two callers hold the identical connection at once, with
 * nothing else in this class able to notice.
 *
 * $onDiscard covers exactly one thing: unhealthy eviction. It is invoked
 * only from inside acquire()'s own idle-reuse loop, for a member that
 * loop is already about to discard because $isHealthy returned false or
 * threw — never for one a caller still holds checked out, and never for
 * an idle member simply sitting unused. See PoolDisposalFailedException
 * for how a disposal failure and a health-check failure combine.
 *
 * There is deliberately no "close every idle member" method, and
 * $onDiscard cannot be used to build one: it is private policy this
 * class alone invokes, a caller has no way to call it directly, no way
 * to enumerate what is currently idle, and repeated acquire()/release()
 * is LIFO — a caller doing that on its own can keep cycling the same
 * member back to itself forever, never visiting the rest. An idle
 * member that is never unhealthy and never independently acquired again
 * simply receives no $onDiscard call at all; it is left to its own
 * object/resource destructor, which runs once nothing (including this
 * pool's own $idle list, once the pool itself goes out of scope) still
 * holds a reference to it — the ordinary way PHP reclaims a resource
 * when the pool becomes unreachable or the process exits. Deterministic,
 * eager shutdown of every idle member is a real gap this class does not
 * yet close; it is not attempted here for a class with no real consumer
 * yet to settle its checked-out-member semantics against.
 *
 * Deliberately has no idea what a "connection" is — not itself a DB or
 * Redis client. Kept as generic infrastructure for a future hand-rolled
 * protocol client that doesn't already pool itself; the real MySQL/
 * Postgres/Redis integrations (`kinetis/persistence`, `kinetis/cache-redis`)
 * don't use it, since their clients pool internally — wrapping an
 * already-pooled client in another pool would be pooling a pool.
 *
 * @template T of object
 */
final class Pool
{
    /** @var list<T> */
    private array $idle = [];

    /**
     * Every member this pool currently owns, keyed by object identity —
     * `true` while idle (also present in $idle) and available to
     * acquire(), `false` while checked out by a caller. A connection
     * absent here entirely was either never created by this pool, or has
     * already been permanently discarded — release() cannot tell those
     * two apart, and treats both identically as foreign.
     *
     * Not declared `readonly`: the property itself is only ever assigned
     * once, in the constructor, but every acquire()/release() call
     * mutates the SplObjectStorage instance it holds via ArrayAccess
     * (`$this->members[$connection] = ...`) — legal in plain PHP (a
     * `readonly` property only guards reassigning the property slot
     * itself, not calling a mutating method on the object already inside
     * it), but Psalm's InaccessibleProperty check cannot tell the two
     * apart and flags every such offsetSet as an illegal write to a
     * `readonly` property. Confirmed directly with a standalone repro
     * before working around it here, rather than assumed: a `readonly`
     * property holding an `SplObjectStorage` genuinely can be mutated
     * this way at runtime, with no error.
     *
     * @var SplObjectStorage<T, bool>
     */
    private SplObjectStorage $members;

    private int $size = 0;

    /**
     * @param Closure(): T $factory
     * @param (Closure(T): bool)|null $isHealthy
     * @param (Closure(T): void)|null $onDiscard
     */
    public function __construct(
        private readonly Closure $factory,
        private readonly ?Closure $isHealthy = null,
        private readonly int $maxSize = 10,
        private readonly ?Closure $onDiscard = null,
    ) {
        if ($maxSize < 1) {
            throw InvalidPoolConfigurationException::maxSizeMustBeAtLeastOne($maxSize);
        }

        $this->members = new SplObjectStorage();
    }

    /**
     * @return T
     */
    public function acquire(): object
    {
        while ($this->idle !== []) {
            $connection = array_pop($this->idle);

            try {
                $healthy = $this->isHealthy === null || ($this->isHealthy)($connection);
            } catch (Throwable $healthCheckFailure) {
                $this->discardAfterHealthCheckFailure($connection, $healthCheckFailure);
            }

            if ($healthy) {
                $this->members[$connection] = false;

                return $connection;
            }

            $this->discardUnhealthyMember($connection);
        }

        if ($this->size >= $this->maxSize) {
            throw PoolExhaustedException::forMaxSize($this->maxSize);
        }

        $this->size++;

        try {
            $connection = ($this->factory)();
        } catch (Throwable $e) {
            // The reservation above only counts successfully-created
            // members — a factory that throws never occupied a slot, so
            // roll it back rather than leaking capacity on every
            // transient failure.
            $this->size--;

            throw $e;
        }

        $this->members[$connection] = false;

        return $connection;
    }

    /**
     * @param T $connection
     */
    public function release(object $connection): void
    {
        if (!$this->members->contains($connection)) {
            throw InvalidPoolReleaseException::foreignMember();
        }

        if ($this->members[$connection] === true) {
            throw InvalidPoolReleaseException::alreadyIdle();
        }

        $this->members[$connection] = true;
        $this->idle[] = $connection;
    }

    public function size(): int
    {
        return $this->size;
    }

    /**
     * Removes $connection from this pool's ownership entirely and gives
     * back the capacity slot it held — the one piece of accounting every
     * discard path must perform exactly once, regardless of what happens
     * afterward (a health check that already threw, or a disposal
     * callback about to be attempted and possibly fail too). Called
     * before any disposal attempt specifically so a throwing $onDiscard
     * can never leave size overcounted.
     *
     * @param T $connection
     */
    private function forget(object $connection): void
    {
        $this->members->detach($connection);
        $this->size--;
    }

    /**
     * $healthCheckFailure is the exception $isHealthy itself threw —
     * always the primary failure here, per the class docblock's own
     * ownership/disposal contract, so this always ends by throwing it
     * (or a PoolDisposalFailedException carrying it as
     * healthCheckFailure(), never dropping it) rather than returning.
     *
     * @param T $connection
     */
    private function discardAfterHealthCheckFailure(object $connection, Throwable $healthCheckFailure): never
    {
        $this->forget($connection);

        if ($this->onDiscard !== null) {
            try {
                ($this->onDiscard)($connection);
            } catch (Throwable $disposalFailure) {
                throw PoolDisposalFailedException::afterHealthCheckThrew($healthCheckFailure, $disposalFailure);
            }
        }

        throw $healthCheckFailure;
    }

    /**
     * $connection already failed its health check cleanly (a plain
     * `false`, not a thrown exception) — disposal is attempted the same
     * way, but a disposal failure here has no health-check Throwable to
     * accompany, so it is itself the whole story PoolDisposalFailedException
     * reports.
     *
     * @param T $connection
     */
    private function discardUnhealthyMember(object $connection): void
    {
        $this->forget($connection);

        if ($this->onDiscard === null) {
            return;
        }

        try {
            ($this->onDiscard)($connection);
        } catch (Throwable $disposalFailure) {
            throw PoolDisposalFailedException::whileDiscardingUnhealthyMember($disposalFailure);
        }
    }
}
