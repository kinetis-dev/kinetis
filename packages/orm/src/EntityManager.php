<?php

declare(strict_types=1);

namespace Kinetis\Orm;

use Fiber;
use Kinetis\Orm\Exception\ClosedEntityManagerException;
use Kinetis\Orm\Exception\CrossFiberAccessException;
use Kinetis\Orm\Exception\MappingException;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\QueryBuilder\Query;
use WeakMap;

/**
 * One unit of work's read state: the identity map of the entities it has
 * loaded, owned by the Fiber that opened it. OrmFactory::open() creates
 * every instance, and none is ever shared between requests, jobs or
 * concurrent Fibers.
 *
 * An identity is the entity class and the identifier converted from the
 * loaded row, so one row is one object for as long as this manager holds
 * it. A later row for a held identity returns that object and writes none
 * of its properties.
 *
 * Every method but close() and isClosed(), and every repository, query and
 * terminal created through this manager, refuses a closed manager and a
 * Fiber other than the one that opened it, before SQL runs or state
 * changes. close() accepts any caller, so whoever owns the unit of work can
 * end it.
 */
final class EntityManager
{
    /** @var Fiber<mixed, mixed, mixed, mixed>|null null is the main context */
    private readonly ?Fiber $owner;

    /** @var array<class-string, array<int|string, object>> */
    private array $identities = [];

    /** @var WeakMap<object, true> */
    private WeakMap $managed;

    private bool $closed = false;

    /**
     * @param array<class-string, EntityPlan<object>> $plans
     */
    private function __construct(
        private readonly MysqlLink|PostgresLink $link,
        private readonly array $plans,
    ) {
        $this->owner = Fiber::getCurrent();
        $this->managed = new WeakMap();
    }

    /**
     * @internal OrmFactory::open() is the way to a manager.
     *
     * @param array<class-string, EntityPlan<object>> $plans
     */
    public static function open(MysqlLink|PostgresLink $link, array $plans): self
    {
        return new self($link, $plans);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return EntityRepository<T>
     * @throws MappingException when $class is not an entity in the factory's metadata
     */
    public function repository(string $class): EntityRepository
    {
        $this->assertUsable();

        /** @var EntityPlan<T> $plan */
        $plan = $this->plans[$class] ?? throw MappingException::unknownEntity($class);

        return new EntityRepository($this, $plan);
    }

    /** Whether $entity is an object this manager loaded and still holds. */
    public function contains(object $entity): bool
    {
        $this->assertUsable();

        return isset($this->managed[$entity]);
    }

    /**
     * Detaches every entity, so the next load of any row builds a new
     * object. Runs no I/O.
     */
    public function clear(): void
    {
        $this->assertUsable();
        $this->detach();
    }

    /**
     * Detaches every entity and refuses every later use. Idempotent. Runs
     * no I/O and leaves the link open: the link belongs to whoever built
     * the factory.
     */
    public function close(): void
    {
        $this->closed = true;
        $this->detach();
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * @internal
     *
     * @throws ClosedEntityManagerException
     * @throws CrossFiberAccessException
     */
    public function assertUsable(): void
    {
        if ($this->closed) {
            throw new ClosedEntityManagerException();
        }

        if (Fiber::getCurrent() !== $this->owner) {
            throw new CrossFiberAccessException();
        }
    }

    /**
     * @internal A fresh select of every mapped column of the entity's table.
     *
     * @param EntityPlan<object> $plan
     */
    public function select(EntityPlan $plan): Query
    {
        $this->assertUsable();

        return new Query($this->link)->table($plan->table)->select(...$plan->columns());
    }

    /**
     * @internal
     *
     * @template T of object
     * @param EntityPlan<T> $plan
     * @return T|null
     */
    public function managed(EntityPlan $plan, int|string $id): ?object
    {
        $this->assertUsable();

        /** @var T|null */
        return $this->identities[$plan->class][$id] ?? null;
    }

    /**
     * @internal Converts every row before allocating anything, so a row
     *           that fails leaves no entity allocated or registered. Each
     *           converted identity then resolves to the object this manager
     *           already holds, untouched, or to a new one registered only
     *           after all of its properties are written.
     *
     * @template T of object
     * @param EntityPlan<T> $plan
     * @param list<array<string, mixed>> $rows
     * @return list<T>
     */
    public function load(EntityPlan $plan, array $rows): array
    {
        // Checked again after the SQL that produced $rows: the Fiber may
        // have suspended there while the unit of work was closed.
        $this->assertUsable();

        $converted = array_map($plan->convertRow(...), $rows);
        $entities = [];

        foreach ($converted as [$id, $values]) {
            $entity = $this->identities[$plan->class][$id] ?? null;

            if ($entity === null) {
                $entity = $plan->instantiate($values);
                $this->identities[$plan->class][$id] = $entity;
                $this->managed[$entity] = true;
            }

            $entities[] = $entity;
        }

        /** @var list<T> $entities */
        return $entities;
    }

    private function detach(): void
    {
        $this->identities = [];
        $this->managed = new WeakMap();
    }
}
