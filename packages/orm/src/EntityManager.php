<?php

declare(strict_types=1);

namespace Kinetis\Orm;

use Fiber;
use Kinetis\Orm\Exception\ClosedEntityManagerException;
use Kinetis\Orm\Exception\CrossFiberAccessException;
use Kinetis\Orm\Exception\InvalidEntityStateException;
use Kinetis\Orm\Exception\MappingException;
use Kinetis\Orm\Exception\RollbackFailedException;
use Kinetis\Orm\Exception\UnknownFlushOutcomeException;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Persistence\Contract\SqlTransaction;
use Kinetis\QueryBuilder\Query;
use Throwable;
use WeakMap;

/**
 * One unit of work, owned by the Fiber that opened it: the identity map of
 * the entities it holds, the snapshot each managed entity is compared
 * against, and the inserts and deletes scheduled for the next flush().
 * OrmFactory::open() creates every instance, and none is ever shared
 * between requests, jobs or concurrent Fibers.
 *
 * An identity is the entity class and its identifier, so one row is one
 * object for as long as this manager holds it. A later row for a held
 * identity returns that object and writes neither its properties nor its
 * snapshot.
 *
 * flush() writes from a plan local to the call, and nothing in this
 * manager or its entities changes until COMMIT returns. A flush that fails
 * before COMMIT therefore leaves every change pending as it was.
 *
 * Every method but close() and isClosed(), and every repository, query and
 * terminal created through this manager, refuses a closed manager, a Fiber
 * other than the one that opened it, and a call while flush() runs, before
 * SQL runs or state changes. close() accepts any caller at any moment, so
 * whoever owns the unit of work can end it.
 *
 * @phpstan-type Values array<string, null|bool|int|float|string>
 * @phpstan-type Insert array{entity: object, plan: EntityPlan<object>, values: Values}
 * @phpstan-type Write array{entity: object, plan: EntityPlan<object>, id: int|string, values: Values|null, changes: Values}
 * @psalm-type Values = array<string, null|bool|int|float|string>
 * @psalm-type Insert = array{entity: object, plan: EntityPlan<object>, values: Values}
 * @psalm-type Write = array{entity: object, plan: EntityPlan<object>, id: int|string, values: Values|null, changes: Values}
 */
final class EntityManager
{
    /** @var Fiber<mixed, mixed, mixed, mixed>|null null is the main context */
    private readonly ?Fiber $owner;

    /**
     * Every managed entity, and every entity awaiting insert whose
     * identifier is assigned.
     *
     * @var array<class-string, array<int|string, object>>
     */
    private array $identities = [];

    /**
     * The database values each managed entity was loaded or last flushed
     * with. An entity awaiting insert has none.
     *
     * @var WeakMap<object, Values>
     */
    private WeakMap $snapshots;

    /**
     * Entities awaiting insert, in persist() order, keyed by object id, each
     * with the identifier it was persisted with: null when generated.
     * Holding the entity keeps its object id from being reused.
     *
     * @var array<int, array{object, int|string|null}>
     */
    private array $inserts = [];

    /** @var WeakMap<object, true> managed entities scheduled for deletion */
    private WeakMap $removals;

    private bool $closed = false;

    private bool $flushing = false;

    /** The transaction a running flush() began, for close() to end. */
    private ?SqlTransaction $transaction = null;

    /**
     * @param array<class-string, EntityPlan<object>> $plans
     */
    private function __construct(
        private readonly MysqlLink|PostgresLink $link,
        private readonly array $plans,
    ) {
        $this->owner = Fiber::getCurrent();
        $this->snapshots = new WeakMap();
        $this->removals = new WeakMap();
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

    /** Whether this manager holds $entity: managed, awaiting insert or scheduled for deletion. */
    public function contains(object $entity): bool
    {
        $this->assertUsable();

        return isset($this->snapshots[$entity]) || isset($this->inserts[spl_object_id($entity)]);
    }

    /**
     * Schedules an entity this manager does not hold for insert, after
     * validating it; keeps a managed entity, cancelling its deletion.
     *
     * @throws InvalidEntityStateException
     * @throws MappingException for a property value its type does not admit
     */
    public function persist(object $entity): void
    {
        $this->assertUsable();
        $plan = $this->plans[$entity::class] ?? throw InvalidEntityStateException::unmapped($entity::class);

        if (isset($this->snapshots[$entity])) {
            unset($this->removals[$entity]);

            return;
        }

        if (isset($this->inserts[spl_object_id($entity)])) {
            return;
        }

        /** @var int|string|null $id an identifier property is typed int or string */
        $id = $plan->extract($entity)[$plan->id];

        if ($plan->generated) {
            if ($id !== null) {
                throw InvalidEntityStateException::generatedIdentifierSet($plan->class);
            }
        } elseif ($id === null) {
            throw InvalidEntityStateException::nullIdentifier($plan->class);
        } elseif (isset($this->identities[$plan->class][$id])) {
            throw InvalidEntityStateException::identityConflict($plan->class);
        } else {
            $this->identities[$plan->class][$id] = $entity;
        }

        $this->inserts[spl_object_id($entity)] = [$entity, $id];
    }

    /**
     * Schedules a managed entity for deletion, or detaches an entity
     * awaiting insert without SQL.
     *
     * @throws InvalidEntityStateException for an object this manager does not hold
     */
    public function remove(object $entity): void
    {
        $this->assertUsable();
        $pending = $this->inserts[spl_object_id($entity)] ?? null;

        if ($pending !== null) {
            unset($this->inserts[spl_object_id($entity)]);

            if ($pending[1] !== null) {
                unset($this->identities[$entity::class][$pending[1]]);
            }

            return;
        }

        if (!isset($this->snapshots[$entity])) {
            throw InvalidEntityStateException::notHeld($entity::class);
        }

        $this->removals[$entity] = true;
    }

    /**
     * Writes every scheduled insert, every change to a managed entity and
     * every scheduled deletion in one transaction on the factory's client.
     * Every entity is read and validated, and every change computed, before
     * the transaction begins; with nothing to write, nothing runs. The
     * package README's "Flushing" states the statements, the row checks and
     * the failure contract.
     *
     * @throws InvalidEntityStateException
     * @throws MappingException for a property value its type does not admit
     * @throws RollbackFailedException
     * @throws UnknownFlushOutcomeException
     */
    public function flush(): void
    {
        $this->assertUsable();
        // Set before the first entity is read: everything after this can
        // reach application code on this Fiber, SQL instrumentation included.
        $this->flushing = true;

        try {
            [$inserts, $writes] = $this->plan();

            if ($inserts !== [] || $writes !== []) {
                $this->commit($inserts, $writes);
            }
        } finally {
            $this->flushing = false;
        }
    }

    /**
     * Detaches every entity and abandons every unflushed change, with no
     * I/O. The next load of any row builds a new object.
     */
    public function clear(): void
    {
        $this->assertUsable();
        $this->detach();
    }

    /**
     * Detaches every entity, abandons every unflushed change and refuses
     * every later use. Idempotent, never flushes, and leaves the link open:
     * the link belongs to whoever built the factory. A running flush()'s
     * transaction is closed, after this manager's state is already gone.
     */
    public function close(): void
    {
        $this->closed = true;
        $transaction = $this->transaction;
        $this->transaction = null;
        $this->detach();
        $transaction?->close();
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
     * @throws InvalidEntityStateException while flush() runs
     */
    public function assertUsable(): void
    {
        if ($this->closed) {
            throw new ClosedEntityManagerException();
        }

        if (Fiber::getCurrent() !== $this->owner) {
            throw new CrossFiberAccessException();
        }

        if ($this->flushing) {
            throw InvalidEntityStateException::flushInProgress();
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
     *           already holds, untouched, or to a new one registered with
     *           its snapshot only after all of its properties are written.
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
                $this->snapshots[$entity] = $plan->snapshot($values);
            }

            $entities[] = $entity;
        }

        /** @var list<T> $entities */
        return $entities;
    }

    /**
     * Every insert, in persist() order, and every update or delete, ordered
     * by class and identifier so concurrent flushes lock rows in one order.
     *
     * @return array{list<Insert>, list<Write>}
     * @throws InvalidEntityStateException
     * @throws MappingException
     */
    private function plan(): array
    {
        $inserts = [];

        foreach ($this->inserts as [$entity, $id]) {
            $plan = $this->plans[$entity::class];
            $values = $plan->extract($entity);

            if ($values[$plan->id] !== $id) {
                throw InvalidEntityStateException::identifierChanged($plan->class);
            }

            $inserts[] = ['entity' => $entity, 'plan' => $plan, 'values' => $values];
        }

        $writes = [];

        foreach ($this->identities as $class => $entities) {
            $plan = $this->plans[$class];

            foreach ($entities as $entity) {
                $snapshot = $this->snapshots[$entity] ?? null;

                if ($snapshot === null) {
                    continue;
                }

                /** @var int|string $id */
                $id = $snapshot[$plan->id];
                $values = $plan->extract($entity);

                if ($values[$plan->id] !== $id) {
                    throw InvalidEntityStateException::identifierChanged($plan->class);
                }

                if (isset($this->removals[$entity])) {
                    $writes[] = ['entity' => $entity, 'plan' => $plan, 'id' => $id, 'values' => null, 'changes' => []];

                    continue;
                }

                $changes = array_filter(
                    $values,
                    static fn (mixed $value, string $name): bool => $value !== $snapshot[$name],
                    ARRAY_FILTER_USE_BOTH,
                );

                if ($changes !== []) {
                    $writes[] = ['entity' => $entity, 'plan' => $plan, 'id' => $id, 'values' => $values, 'changes' => $changes];
                }
            }
        }

        // One class's identifiers share a type: an int compares numerically,
        // a string by its bytes.
        usort($writes, static fn (array $a, array $b): int => strcmp($a['plan']->class, $b['plan']->class)
            ?: (is_int($a['id']) && is_int($b['id']) ? $a['id'] <=> $b['id'] : strcmp((string) $a['id'], (string) $b['id'])));

        return [$inserts, $writes];
    }

    /**
     * @param list<Insert> $inserts
     * @param list<Write> $writes
     * @throws RollbackFailedException
     * @throws UnknownFlushOutcomeException
     */
    private function commit(array $inserts, array $writes): void
    {
        $transaction = $this->link->beginTransaction();
        $this->transaction = $transaction;
        $committing = false;

        try {
            $generated = $this->write($transaction, $inserts, $writes);
            $committing = true;
            $transaction->commit();
        } catch (Throwable $failure) {
            if ($committing) {
                $this->closed = true;
                $this->detach();

                throw new UnknownFlushOutcomeException($failure);
            }

            // No COMMIT was sent, so whether this manager keeps its work
            // rests on the rollback alone. rollback() ends an active
            // transaction and returns on one that already ended.
            try {
                $transaction->rollback();
            } catch (Throwable $rollbackFailure) {
                $this->closed = true;
                $this->detach();

                throw new RollbackFailedException($failure, $rollbackFailure);
            }

            throw $failure;
        } finally {
            $this->transaction = null;
        }

        // A close() while COMMIT was answered has already detached everything.
        if (!$this->closed) {
            $this->apply($inserts, $writes, $generated);
        }
    }

    /**
     * Runs every statement on $transaction and returns the identifier each
     * generated insert reported, by its position in $inserts. No entity is
     * written.
     *
     * @param list<Insert> $inserts
     * @param list<Write> $writes
     * @return array<int, int>
     */
    private function write(SqlTransaction $transaction, array $inserts, array $writes): array
    {
        $this->assertOpen();
        $generated = [];

        foreach ($inserts as $i => ['plan' => $plan, 'values' => $values]) {
            $query = new Query($transaction)->table($plan->table);

            if (!$plan->generated) {
                $query->insert($plan->row($values));
                $this->assertOpen();

                continue;
            }

            unset($values[$plan->id]);
            $key = $query->insertGetId($plan->row($values), $plan->column($plan->id));
            $this->assertOpen();
            $generated[$i] = $plan->generatedIdentifier($key);
        }

        foreach ($writes as ['plan' => $plan, 'id' => $id, 'values' => $values, 'changes' => $changes]) {
            $statement = $values === null ? 'DELETE' : 'UPDATE';
            $row = new Query($transaction)->table($plan->table)->where($plan->column($plan->id), '=', $id);
            $affected = $values === null ? $row->delete() : $row->update($plan->row($changes));
            // The MySQL family reports changed rows, not matched ones, so an
            // UPDATE writing the values its row already holds affects none.
            $exists = $affected === 0 && $values !== null
                && new Query($transaction)->table($plan->table)->where($plan->column($plan->id), '=', $id)->exists();
            $this->assertOpen();

            if ($affected > 1) {
                throw InvalidEntityStateException::ambiguousRow($plan->class, $statement, $affected);
            }

            if ($affected === 0 && !$exists) {
                throw InvalidEntityStateException::missingRow($plan->class, $statement);
            }
        }

        return $generated;
    }

    /**
     * Snapshots every written entity with the values the flush sent, not
     * whatever its properties hold by now.
     *
     * @param list<Insert> $inserts
     * @param list<Write> $writes
     * @param array<int, int> $generated
     */
    private function apply(array $inserts, array $writes, array $generated): void
    {
        foreach ($inserts as $i => ['entity' => $entity, 'plan' => $plan, 'values' => $values]) {
            if ($plan->generated) {
                $plan->assignIdentifier($entity, $generated[$i]);
                $values[$plan->id] = $generated[$i];
                $this->identities[$plan->class][$generated[$i]] = $entity;
            }

            unset($this->inserts[spl_object_id($entity)]);
            $this->snapshots[$entity] = $values;
        }

        foreach ($writes as ['entity' => $entity, 'plan' => $plan, 'id' => $id, 'values' => $values]) {
            if ($values === null) {
                unset($this->identities[$plan->class][$id], $this->snapshots[$entity], $this->removals[$entity]);
            } else {
                $this->snapshots[$entity] = $values;
            }
        }
    }

    /**
     * A close() while flush() waited on its transaction, or from code the
     * flush ran, stops the flush before it sends anything more.
     *
     * @throws ClosedEntityManagerException
     */
    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new ClosedEntityManagerException();
        }
    }

    private function detach(): void
    {
        $this->identities = [];
        $this->snapshots = new WeakMap();
        $this->inserts = [];
        $this->removals = new WeakMap();
    }
}
