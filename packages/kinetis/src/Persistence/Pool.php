<?php

declare(strict_types=1);

namespace Kinetis\Persistence;

use Kinetis\Persistence\Exception\PoolExhaustedException;
use Closure;

/**
 * A generic, protocol-agnostic connection pool: holds up to $maxSize
 * connections built by $factory, health-checks them via $isHealthy before
 * handing one out, and discards (rather than hands out) any that fail the
 * check — a connection recycled across worker-loop iterations that's
 * silently gone dead is exactly the failure mode a persistent-worker
 * framework has to guard against that boot-and-die never had to.
 *
 * Deliberately has no idea what a "connection" is — not itself a DB or
 * Redis client. Kept as generic infrastructure for a future hand-rolled
 * protocol client that doesn't already pool itself; the real MySQL/
 * Postgres/Redis integrations (`kinetis/persistence`, `kinetis/cache-redis`)
 * don't use it, since their underlying AMPHP clients already pool
 * internally — wrapping an already-pooled client in another pool would
 * be pooling a pool.
 *
 * @template T of object
 */
final class Pool
{
    /** @var list<T> */
    private array $idle = [];

    private int $size = 0;

    /**
     * @param Closure(): T $factory
     * @param (Closure(T): bool)|null $isHealthy
     */
    public function __construct(
        private readonly Closure $factory,
        private readonly ?Closure $isHealthy = null,
        private readonly int $maxSize = 10,
    ) {}

    /**
     * @return T
     */
    public function acquire(): object
    {
        while ($this->idle !== []) {
            $connection = array_pop($this->idle);

            if ($this->isHealthy === null || ($this->isHealthy)($connection)) {
                return $connection;
            }

            $this->size--;
        }

        if ($this->size >= $this->maxSize) {
            throw PoolExhaustedException::forMaxSize($this->maxSize);
        }

        $this->size++;

        return ($this->factory)();
    }

    /**
     * @param T $connection
     */
    public function release(object $connection): void
    {
        $this->idle[] = $connection;
    }

    public function size(): int
    {
        return $this->size;
    }
}
