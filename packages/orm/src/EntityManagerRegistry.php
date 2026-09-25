<?php

declare(strict_types=1);

namespace Kinetis\Orm;

use Fiber;
use InvalidArgumentException;
use Kinetis\Orm\Exception\ClosedEntityManagerException;
use Kinetis\Orm\Exception\CrossFiberAccessException;
use Kinetis\Orm\Exception\MappingException;
use Throwable;

/**
 * One unit of work's EntityManagers: at most one per connection, opened
 * from the OrmFactoryRegistry the first time that connection is asked
 * for. Owned by the Fiber that created it, as every manager it opens is.
 * Each manager keeps its own link, identity map, unit of work and
 * transactions, so no flush or transaction spans two connections.
 *
 * Never shared between requests, jobs or concurrent Fibers: create one per
 * unit of work and close() it when that unit ends.
 */
final class EntityManagerRegistry
{
    /** @var Fiber<mixed, mixed, mixed, mixed>|null null is the main context */
    private readonly ?Fiber $owner;

    /** @var array<int, EntityManager> keyed by the object id of the factory that opened each */
    private array $managers = [];

    private bool $closed = false;

    private function __construct(private readonly OrmFactoryRegistry $factories)
    {
        $this->owner = Fiber::getCurrent();
    }

    /**
     * A registry owned by the calling Fiber that has opened no manager yet.
     */
    public static function create(OrmFactoryRegistry $factories): self
    {
        return new self($factories);
    }

    /**
     * The manager of $connection, opened on the first call.
     *
     * @throws ClosedEntityManagerException after close()
     * @throws CrossFiberAccessException from a Fiber other than the one that created this registry
     * @throws InvalidArgumentException when the OrmFactoryRegistry has no link for $connection
     */
    public function manager(string $connection): EntityManager
    {
        $this->assertUsable();

        return $this->open($this->factories->factory($connection));
    }

    /**
     * The manager of the connection $class lives on, opened on the first
     * call for that connection.
     *
     * @param class-string $class
     * @throws ClosedEntityManagerException after close()
     * @throws CrossFiberAccessException from a Fiber other than the one that created this registry
     * @throws MappingException when $class is not an entity in the metadata
     */
    public function managerFor(string $class): EntityManager
    {
        $this->assertUsable();

        return $this->open($this->factories->factoryFor($class));
    }

    /**
     * Closes every manager this registry opened, without flushing, and
     * refuses every later manager() and managerFor(). Idempotent, and
     * callable from any Fiber, so whoever ends the unit of work can run
     * it. Every manager is closed even when closing one throws; the first
     * failure is rethrown afterwards.
     */
    public function close(): void
    {
        $this->closed = true;
        $managers = $this->managers;
        $this->managers = [];
        $failure = null;

        foreach ($managers as $manager) {
            try {
                $manager->close();
            } catch (Throwable $e) {
                $failure ??= $e;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    private function open(OrmFactory $factory): EntityManager
    {
        return $this->managers[spl_object_id($factory)] ??= $factory->open();
    }

    /**
     * @throws ClosedEntityManagerException
     * @throws CrossFiberAccessException
     */
    private function assertUsable(): void
    {
        if ($this->closed) {
            throw ClosedEntityManagerException::registry();
        }

        if (Fiber::getCurrent() !== $this->owner) {
            throw CrossFiberAccessException::registry();
        }
    }
}
