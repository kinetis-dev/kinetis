<?php

declare(strict_types=1);

namespace Kinetis\Orm;

use Closure;
use Fiber;
use Kinetis\Orm\Exception\ClosedEntityManagerException;
use Kinetis\Orm\Exception\CrossFiberAccessException;
use Kinetis\Orm\Exception\InvalidEntityStateException;
use Kinetis\Orm\Exception\MappingException;
use Kinetis\Orm\Exception\OptimisticLockException;
use Kinetis\Orm\Exception\RollbackFailedException;
use Kinetis\Orm\Exception\UnknownFlushOutcomeException;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\MysqlTransaction;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Persistence\Contract\PostgresTransaction;
use Kinetis\Persistence\Contract\SqlTransaction;
use Kinetis\QueryBuilder\Query;
use Throwable;
use WeakMap;

/**
 * One unit of work, owned by the Fiber that opened it: the identity map of
 * the entities it holds, the snapshot each managed entity is compared
 * against, and the inserts and deletes scheduled for the next flush().
 * OrmFactory::open() and OrmFactory::transaction() create every instance,
 * and none is ever shared between requests, jobs or concurrent Fibers.
 *
 * A manager from open() reads through the factory's client, and each
 * flush() writes in a transaction it begins there. A manager from
 * transaction() is bound to the transaction the factory began: every read,
 * lock and flush statement runs on it, and the factory ends it.
 *
 * An identity is the entity class and its identifier, so one row is one
 * object for as long as this manager holds it. A later row for a held
 * identity returns that object and writes neither its properties nor its
 * snapshot.
 *
 * A relationship is loaded only on request, by the entity query terminal
 * that selected its source entities and on the same link, and its targets
 * resolve through the same identity map. flush() writes only a #[BelongsTo]
 * relationship, which holds an entity this manager already manages, or
 * null, and never reads an inverse relationship.
 *
 * flush() writes from a plan local to the call, and nothing in this
 * manager or its entities changes until COMMIT returns: flush()'s own, or
 * for a bound manager the factory's. A flush that fails before COMMIT
 * therefore leaves every change pending as it was.
 *
 * Every method but close() and isClosed(), and every repository, query and
 * terminal created through this manager, refuses a closed manager, a Fiber
 * other than the one that opened it, a call while flush() runs, and on a
 * bound manager a call after its flush wrote or a terminal or flush failed,
 * before SQL runs or state changes. close() accepts any caller at any
 * moment, so whoever owns the unit of work can end it.
 *
 * @phpstan-type Values array<string, null|bool|int|float|string>
 * @phpstan-type Insert array{entity: object, plan: EntityPlan<object>, values: Values}
 * @phpstan-type Write array{entity: object, plan: EntityPlan<object>, id: int|string, version: int|null, values: Values|null, changes: Values}
 * @psalm-type Values = array<string, null|bool|int|float|string>
 * @psalm-type Insert = array{entity: object, plan: EntityPlan<object>, values: Values}
 * @psalm-type Write = array{entity: object, plan: EntityPlan<object>, id: int|string, version: int|null, values: Values|null, changes: Values}
 * @phpstan-import-type InverseMapping from MetadataRegistry
 * @psalm-import-type InverseMapping from MetadataRegistry
 */
final class EntityManager
{
    /** The most foreign keys one relationship select binds. */
    private const int RELATIONSHIP_BATCH = 1000;

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

    /** The transaction a running flush() began, or a bound manager's, for close() to end. */
    private ?SqlTransaction $transaction = null;

    /** A bound manager's first failed terminal or flush(). */
    private ?Throwable $failure = null;

    /**
     * A bound manager's written flush with the identifiers it generated, for
     * finish() to apply once the factory's COMMIT returns.
     *
     * @var array{list<Insert>, list<Write>, array<int, int>}|null
     */
    private ?array $flushed = null;

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
     * @internal OrmFactory::transaction() binds a manager to the transaction
     *           it began, which is then this manager's only link.
     *
     * @param array<class-string, EntityPlan<object>> $plans
     */
    public static function bind(MysqlTransaction|PostgresTransaction $transaction, array $plans): self
    {
        $manager = new self($transaction, $plans);
        $manager->transaction = $transaction;

        return $manager;
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
        $id = $plan->extract($entity, null, $this->heldIdentifier(...))[$plan->id];

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
     * every scheduled deletion in one transaction. Every entity is read and
     * validated, and every change computed, before any statement; with
     * nothing to write, nothing runs. A manager from open() begins the
     * transaction on the factory's client and commits it. A bound manager
     * writes on its transaction and leaves COMMIT to the factory. The package
     * README's "Flushing" and "Transaction sessions" state the statements,
     * the row checks and the failure contract.
     *
     * @throws InvalidEntityStateException
     * @throws MappingException for a property value its type does not admit
     * @throws OptimisticLockException
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

            if ($inserts === [] && $writes === []) {
                return;
            }

            if ($this->link instanceof SqlTransaction) {
                $this->flushed = [$inserts, $writes, $this->write($this->link, $inserts, $writes)];
            } else {
                $this->commit($inserts, $writes);
            }
        } catch (Throwable $failure) {
            $this->record($failure);

            throw $failure;
        } finally {
            $this->flushing = false;
        }
    }

    /**
     * A fresh Kinetis\QueryBuilder\Query on this manager's link: the
     * factory's client, or a bound manager's transaction. Its terminals
     * return arrays or DTOs this manager never manages, and nothing it
     * writes reaches a snapshot or version this manager holds.
     */
    public function builder(): Query
    {
        $this->assertUsable();

        return new Query($this->link);
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
     * transaction, or a bound manager's, is closed after this manager's
     * state is already gone, so a bound manager's work is never committed.
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
     * @throws InvalidEntityStateException while flush() runs, and on a bound
     *         manager after its flush wrote or a terminal or flush failed
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

        if ($this->failure !== null) {
            throw InvalidEntityStateException::sessionFailed($this->failure);
        }

        if ($this->flushed !== null) {
            throw InvalidEntityStateException::sessionFlushed();
        }
    }

    /**
     * @internal A lock outlives its statement only inside a transaction.
     *
     * @throws InvalidEntityStateException for a manager not bound to one
     */
    public function assertLockable(): void
    {
        $this->assertUsable();

        if (!$this->link instanceof SqlTransaction) {
            throw InvalidEntityStateException::lockOutsideTransaction();
        }
    }

    /**
     * @internal Runs a terminal from its Query call onward. A bound manager
     *           records the first failure, which refuses every later use
     *           and makes the factory roll back.
     *
     * @template TResult
     * @param Closure(): TResult $terminal
     * @return TResult
     */
    public function terminal(Closure $terminal): mixed
    {
        try {
            return $terminal();
        } catch (Throwable $failure) {
            $this->record($failure);

            throw $failure;
        }
    }

    /** @internal The first failure a bound manager recorded. */
    public function failure(): ?Throwable
    {
        return $this->failure;
    }

    /**
     * @internal OrmFactory::transaction() calls this once its transaction
     *           has ended, $committed only when COMMIT returned. The flushed
     *           work is applied then, unless close() ran meanwhile, and the
     *           manager is closed without touching the transaction again.
     */
    public function finish(bool $committed): void
    {
        $this->transaction = null;

        if ($committed && !$this->closed && $this->flushed !== null) {
            [$inserts, $writes, $generated] = $this->flushed;
            $this->apply($inserts, $writes, $generated);
        }

        $this->close();
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
     * @internal The entities of $rows, as register() resolves them, with
     *           every relationship $relations names loaded into them.
     *
     * @template T of object
     * @param EntityPlan<T> $plan
     * @param list<array<string, mixed>> $rows
     * @param array<string, array<array-key, mixed>> $relations property => the relationships to load below it
     * @return list<T>
     */
    public function load(EntityPlan $plan, array $rows, array $relations = []): array
    {
        /** @var list<T> $entities */
        $entities = array_map(static fn (array $row): object => $row[1], $this->register($plan, $rows));
        $this->eager($plan, $entities, $relations);

        return $entities;
    }

    /**
     * @internal $relations with the relationship path $path merged in: every
     *           dot-separated segment a relationship of the entity the
     *           segment before it loads.
     *
     * @param EntityPlan<object> $plan
     * @param array<string, array<array-key, mixed>> $relations
     * @return array<string, array<array-key, mixed>>
     * @throws MappingException
     */
    public function mergeRelation(EntityPlan $plan, array $relations, string $path): array
    {
        $segments = explode('.', $path);

        if (in_array('', $segments, true)) {
            throw MappingException::invalidRelationPath($plan->class, $path);
        }

        $property = array_shift($segments);
        $target = $this->plans[$plan->target($property)];
        /** @var array<string, array<array-key, mixed>> $below */
        $below = $relations[$property] ?? [];
        $relations[$property] = $segments === [] ? $below : $this->mergeRelation($target, $below, implode('.', $segments));

        return $relations;
    }

    /**
     * Converts every row before allocating anything, so a row that fails
     * leaves no entity allocated or registered. Each converted identity then
     * resolves to the object this manager already holds, untouched, or to a
     * new one registered with its snapshot only after all of its properties
     * are written.
     *
     * @template T of object
     * @param EntityPlan<T> $plan
     * @param list<array<string, mixed>> $rows
     * @return list<array{int|string, T, array<string, mixed>}> each row's identifier, entity and converted values
     */
    private function register(EntityPlan $plan, array $rows): array
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

            $entities[] = [$id, $entity, $values];
        }

        /** @var list<array{int|string, T, array<string, mixed>}> $entities */
        return $entities;
    }

    /**
     * Loads each relationship $relations names into $entities, then the
     * relationships below it into the targets, one level at a time.
     *
     * An uninitialized relationship takes the foreign key of its entity's
     * snapshot. Every distinct non-null key is selected from the target
     * table, RELATIONSHIP_BATCH keys per statement, whether or not its
     * target is already held, and each row goes through register(), which
     * refuses a manager closed during that statement before anything is
     * assigned. A null key assigns null, and a key no row matches throws.
     * A relationship already initialized is never overwritten: its target
     * only joins the next level, and must be managed by this manager. An
     * inverse relationship loads as loadInverse() states.
     *
     * @param EntityPlan<object> $plan
     * @param list<object> $entities
     * @param array<string, array<array-key, mixed>> $relations
     * @throws InvalidEntityStateException
     * @throws MappingException
     */
    private function eager(EntityPlan $plan, array $entities, array $relations): void
    {
        foreach ($relations as $property => $below) {
            $target = $this->plans[$plan->target($property)];
            $inverse = $plan->inverse($property);

            if ($inverse !== null) {
                /** @var array<string, array<array-key, mixed>> $below */
                $this->eager($target, $this->loadInverse($plan, $target, $entities, $inverse), $below);

                continue;
            }

            $unloaded = [];
            $keys = [];
            $next = [];

            foreach ($entities as $entity) {
                if ($plan->initialized($entity, $property)) {
                    /** @var object|null $related a #[BelongsTo] property is typed with its target class */
                    $related = $plan->related($entity, $property);

                    if ($related !== null) {
                        $next[spl_object_id($related)] = isset($this->snapshots[$related])
                            ? $related
                            : throw InvalidEntityStateException::relationTargetNotHeld($plan->class, $property);
                    }

                    continue;
                }

                $snapshot = $this->snapshots[$entity] ?? throw InvalidEntityStateException::uninitialized($plan->class, $property);
                /** @var int|string|null $key a relationship's database value is its target's identifier */
                $key = $snapshot[$property];
                $unloaded[] = [$entity, $key];

                if ($key !== null) {
                    $keys[] = $key;
                }
            }

            $found = [];

            foreach (array_chunk(array_unique($keys), self::RELATIONSHIP_BATCH) as $batch) {
                $rows = $this->select($target)->whereIn($target->column($target->id), $batch)->get();

                foreach ($this->register($target, $rows) as [$id, $loaded]) {
                    $found[$id] = $loaded;
                }
            }

            foreach ($unloaded as [$entity, $key]) {
                $related = $key === null ? null : $found[$key] ?? throw MappingException::missingRelationTarget(
                    $plan->class,
                    $property,
                    $plan->column($property),
                    $target->class,
                );
                $plan->assign($entity, $property, $related);

                if ($related !== null) {
                    $next[spl_object_id($related)] = $related;
                }
            }

            /** @var array<string, array<array-key, mixed>> $below */
            $this->eager($target, array_values($next), $below);
        }
    }

    /**
     * Loads the inverse relationship $inverse into $entities and returns the
     * next level: every target assigned, or held by an initialized one.
     *
     * An initialized relationship is never overwritten, and every value it
     * holds must be a target this manager manages, or a nullable #[HasOne]'s
     * null. Every other entity takes its identifier from its snapshot. The
     * distinct identifiers are selected against the target's mappedBy
     * foreign-key column, RELATIONSHIP_BATCH per statement and ordered by the
     * target's identifier, and each row goes through register(), which
     * refuses a manager closed during that statement before anything is
     * assigned. A row joins the entity its own foreign-key value names,
     * whatever a held target's snapshot or property holds.
     *
     * @param EntityPlan<object> $plan
     * @param EntityPlan<object> $target
     * @param list<object> $entities
     * @param InverseMapping $inverse
     * @return list<object>
     * @throws InvalidEntityStateException
     * @throws MappingException
     */
    private function loadInverse(EntityPlan $plan, EntityPlan $target, array $entities, array $inverse): array
    {
        $property = $inverse['name'];
        $unloaded = [];
        $keys = [];
        $next = [];

        foreach ($entities as $entity) {
            if (!$plan->initialized($entity, $property)) {
                $snapshot = $this->snapshots[$entity] ?? throw InvalidEntityStateException::uninitialized($plan->class, $property);
                /** @var int|string $key an identifier's database value is an int or a string */
                $key = $snapshot[$plan->id];
                $unloaded[] = [$entity, $key];
                $keys[] = $key;

                continue;
            }

            $related = $plan->related($entity, $property);

            foreach (is_array($related) ? $related : [$related] as $held) {
                if ($held === null && $inverse['kind'] === 'hasOne') {
                    continue;
                }

                if (!$held instanceof $target->class || !isset($this->snapshots[$held])) {
                    throw InvalidEntityStateException::relationTargetNotHeld($plan->class, $property);
                }

                $next[spl_object_id($held)] = $held;
            }
        }

        $column = $target->column($inverse['mappedBy']);
        $found = [];

        foreach (array_chunk(array_unique($keys), self::RELATIONSHIP_BATCH) as $batch) {
            $rows = $this->select($target)->whereIn($column, $batch)->orderBy($target->column($target->id))->get();

            foreach ($this->register($target, $rows) as [, $loaded, $values]) {
                /** @var int|string $owner the foreign key the statement matched */
                $owner = $values[$inverse['mappedBy']];
                $found[$owner][] = $loaded;
            }
        }

        foreach ($unloaded as [$entity, $key]) {
            $loaded = $found[$key] ?? [];
            $related = match (true) {
                $inverse['kind'] === 'hasMany' => $loaded,
                count($loaded) > 1 => throw MappingException::ambiguousInverseTarget($plan->class, $property, $target->class, $column),
                $loaded !== [] => $loaded[0],
                $inverse['nullable'] => null,
                default => throw MappingException::missingInverseTarget($plan->class, $property, $target->class, $column),
            };
            $plan->assign($entity, $property, $related);

            foreach ($loaded as $held) {
                $next[spl_object_id($held)] = $held;
            }
        }

        return array_values($next);
    }

    /** A relationship target's identifier in this manager's snapshot, or null when it does not manage the target. */
    private function heldIdentifier(object $target): int|string|null
    {
        $snapshot = $this->snapshots[$target] ?? null;

        /** @var int|string|null an identifier's database value is an int or a string */
        return $snapshot === null ? null : $snapshot[$this->plans[$target::class]->id];
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
        $identify = $this->heldIdentifier(...);
        $inserts = [];

        foreach ($this->inserts as [$entity, $id]) {
            $plan = $this->plans[$entity::class];
            $values = $plan->extract($entity, null, $identify);

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
                $values = $plan->extract($entity, $snapshot, $identify);

                if ($values[$plan->id] !== $id) {
                    throw InvalidEntityStateException::identifierChanged($plan->class);
                }

                /** @var int|null $version a version property is typed int */
                $version = $plan->version === null ? null : $snapshot[$plan->version];

                if ($plan->version !== null && $values[$plan->version] !== $version) {
                    throw InvalidEntityStateException::versionChanged($plan->class);
                }

                if (isset($this->removals[$entity])) {
                    $writes[] = ['entity' => $entity, 'plan' => $plan, 'id' => $id, 'version' => $version, 'values' => null, 'changes' => []];

                    continue;
                }

                $changes = array_filter(
                    $values,
                    static fn (mixed $value, string $name): bool => $value !== $snapshot[$name],
                    ARRAY_FILTER_USE_BOTH,
                );

                if ($changes === []) {
                    continue;
                }

                // The version is unchanged, so it is not among $changes: the
                // UPDATE sets the next one, which the snapshot takes on COMMIT.
                if ($plan->version !== null) {
                    /** @var int $version set above for a versioned entity */
                    $changes[$plan->version] = $values[$plan->version] = $version !== PHP_INT_MAX
                        ? $version + 1
                        : throw InvalidEntityStateException::versionExhausted($plan->class);
                }

                $writes[] = ['entity' => $entity, 'plan' => $plan, 'id' => $id, 'version' => $version, 'values' => $values, 'changes' => $changes];
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
     * @throws OptimisticLockException
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
     * @throws OptimisticLockException
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

        foreach ($writes as ['plan' => $plan, 'id' => $id, 'version' => $version, 'values' => $values, 'changes' => $changes]) {
            $statement = $values === null ? 'DELETE' : 'UPDATE';
            $row = new Query($transaction)->table($plan->table)->where($plan->column($plan->id), '=', $id);

            if ($plan->version !== null) {
                $row->where($plan->column($plan->version), '=', $version);
            }

            $affected = $values === null ? $row->delete() : $row->update($plan->row($changes));
            // The MySQL family reports changed rows, not matched ones, so an
            // UPDATE writing the values its row already holds affects none. A
            // versioned UPDATE that matches always changes its version, so
            // none means the row is stale.
            $exists = $affected === 0 && $values !== null && $plan->version === null
                && new Query($transaction)->table($plan->table)->where($plan->column($plan->id), '=', $id)->exists();
            $this->assertOpen();

            if ($affected > 1) {
                throw InvalidEntityStateException::ambiguousRow($plan->class, $statement, $affected);
            }

            if ($affected === 0 && $plan->version !== null) {
                throw OptimisticLockException::stale($plan->class, $statement);
            }

            if ($affected === 0 && !$exists) {
                throw InvalidEntityStateException::missingRow($plan->class, $statement);
            }
        }

        return $generated;
    }

    /**
     * Snapshots every written entity with the values the flush sent, not
     * whatever its properties hold by now, and writes each inserted or
     * updated entity's version: the flush owns it, so a change made
     * meanwhile is overwritten.
     *
     * @param list<Insert> $inserts
     * @param list<Write> $writes
     * @param array<int, int> $generated
     */
    private function apply(array $inserts, array $writes, array $generated): void
    {
        foreach ($inserts as $i => ['entity' => $entity, 'plan' => $plan, 'values' => $values]) {
            if ($plan->generated) {
                $plan->assign($entity, $plan->id, $generated[$i]);
                $values[$plan->id] = $generated[$i];
                $this->identities[$plan->class][$generated[$i]] = $entity;
            }

            if ($plan->version !== null) {
                /** @var int $version a version property is typed int */
                $version = $values[$plan->version];
                $plan->assign($entity, $plan->version, $version);
            }

            unset($this->inserts[spl_object_id($entity)]);
            $this->snapshots[$entity] = $values;
        }

        foreach ($writes as ['entity' => $entity, 'plan' => $plan, 'id' => $id, 'values' => $values]) {
            if ($values === null) {
                unset($this->identities[$plan->class][$id], $this->snapshots[$entity], $this->removals[$entity]);

                continue;
            }

            if ($plan->version !== null) {
                /** @var int $next plan() set it */
                $next = $values[$plan->version];
                $plan->assign($entity, $plan->version, $next);
            }

            $this->snapshots[$entity] = $values;
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

    /** A bound manager's failure fails its whole session; an open() manager's flush keeps its own contract. */
    private function record(Throwable $failure): void
    {
        if ($this->link instanceof SqlTransaction) {
            $this->failure ??= $failure;
        }
    }

    private function detach(): void
    {
        $this->identities = [];
        $this->snapshots = new WeakMap();
        $this->inserts = [];
        $this->removals = new WeakMap();
        $this->flushed = null;
    }
}
