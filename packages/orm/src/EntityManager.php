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
use Kinetis\Orm\Flush\FlushPlanner;
use Kinetis\Orm\Flush\PlanNode;
use Kinetis\Orm\Flush\Reference;
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
 * resolve through the same identity map. A #[BelongsTo] relationship is the
 * only one that maps a foreign key, and the only one flush() writes into an
 * entity table.
 *
 * An owning #[ManyToMany] relationship is this manager's join table. It
 * writes the links of a new owner's initialized collection, and the
 * difference between the membership it loaded for a managed owner and the
 * one that collection holds now; removing the owner deletes its join rows
 * before its own. A non-empty difference on a managed owner carrying
 * #[Version] advances that version once, under the same optimistic lock its
 * columns take, so two writers of one membership cannot merge. The inverse
 * side reads the same table backwards and writes nothing, and neither side
 * ever removes a target entity.
 *
 * An owned inverse relationship is this manager's aggregate ownership edge.
 * flush() walks every initialized one once, schedules the new entities it
 * reaches, and reconciles the ones it loaded itself: a target dropped from
 * a loaded relationship is deleted when its foreign key still names its
 * owner, and updated when the application moved it to another. remove()
 * takes the whole aggregate below an entity, and persist() gives it back.
 * The manager never loads a relationship to answer one of those questions,
 * never repairs the object graph, and refuses through automatic discovery
 * an object whose deletion it committed. A join collection is no ownership
 * edge: it discovers nothing and removes no target.
 *
 * flush() writes from an ordered plan local to the call, which puts every
 * row after the rows its foreign keys name, and nothing in this manager or
 * its entities changes until COMMIT returns: flush()'s own, or for a bound
 * manager the factory's. A flush that fails before COMMIT therefore leaves
 * every change pending as it was, the graph it discovered included.
 *
 * Every method but close() and isClosed(), and every repository, query and
 * terminal created through this manager, refuses a closed manager, a Fiber
 * other than the one that opened it, a call while flush() runs, and on a
 * bound manager a call after its flush wrote or a terminal or flush failed,
 * before SQL runs or state changes. close() accepts any caller at any
 * moment, so whoever owns the unit of work can end it.
 *
 * @phpstan-type Values array<string, null|bool|int|float|string>
 * @phpstan-type Applied array{relations: list<array{object, string, list<object>}>, cancelled: list<object>}
 * @psalm-type Values = array<string, null|bool|int|float|string>
 * @psalm-type Applied = array{relations: list<array{object, string, list<object>}>, cancelled: list<object>}
 * @phpstan-import-type InverseMapping from MetadataRegistry
 * @psalm-import-type InverseMapping from MetadataRegistry
 * @phpstan-import-type JoinMapping from MetadataRegistry
 * @psalm-import-type JoinMapping from MetadataRegistry
 * @phpstan-import-type PlanValues from FlushPlanner
 * @psalm-import-type PlanValues from FlushPlanner
 * @phpstan-import-type InsertAction from FlushPlanner
 * @psalm-import-type InsertAction from FlushPlanner
 * @phpstan-import-type UpdateAction from FlushPlanner
 * @psalm-import-type UpdateAction from FlushPlanner
 * @phpstan-import-type DeleteAction from FlushPlanner
 * @psalm-import-type DeleteAction from FlushPlanner
 * @phpstan-import-type LinkAction from FlushPlanner
 * @psalm-import-type LinkAction from FlushPlanner
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
     * Entities awaiting insert, in scheduling order — persist() order, then
     * the order a flush discovered them in — keyed by object id, each with
     * the identifier it was scheduled with: null when generated. Holding the
     * entity keeps its object id from being reused.
     *
     * @var array<int, array{object, int|string|null}>
     */
    private array $inserts = [];

    /** @var WeakMap<object, true> managed entities scheduled for deletion */
    private WeakMap $removals;

    /**
     * The membership this manager loaded for each owned inverse relationship
     * and each owning join collection, or wrote into one, as the identifiers
     * it held then. Only a relationship listed here has a database state to
     * reconcile against; an application-initialized one has none.
     *
     * @var WeakMap<object, array<string, list<int|string>>>
     */
    private WeakMap $relations;

    /**
     * Every entity whose deletion this manager committed. Automatic
     * discovery refuses one, so an unchanged collection cannot reinsert a
     * row the manager deleted, while persist() still inserts it as new.
     *
     * @var WeakMap<object, true>
     */
    private WeakMap $tombstones;

    private bool $closed = false;

    private bool $flushing = false;

    /** The transaction a running flush() began, or a bound manager's, for close() to end. */
    private ?SqlTransaction $transaction = null;

    /** A bound manager's first failed terminal or flush(). */
    private ?Throwable $failure = null;

    /**
     * A bound manager's written flush: its statements, the identifiers they
     * generated and what else COMMIT applies, for finish() to apply once the
     * factory's COMMIT returns.
     *
     * @var array{list<PlanNode>, array<int, int>, Applied}|null
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
        $this->relations = new WeakMap();
        $this->tombstones = new WeakMap();
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
     * validating it; keeps a managed entity, cancelling the deletion of it
     * and of the aggregate below it.
     *
     * A #[BelongsTo] target that is an entity of this factory's metadata is
     * admitted here whether or not this manager holds it yet: flush()
     * validates the whole graph once, so persisting related entities in any
     * order writes them in one transaction.
     *
     * @throws InvalidEntityStateException
     * @throws MappingException for a property value its type does not admit
     */
    public function persist(object $entity): void
    {
        $this->assertUsable();

        if (!isset($this->plans[$entity::class])) {
            throw InvalidEntityStateException::unmapped($entity::class);
        }

        if (isset($this->snapshots[$entity])) {
            $this->keep($entity);

            return;
        }

        if (isset($this->inserts[spl_object_id($entity)])) {
            return;
        }

        // Scheduled first: a refused re-persist leaves the committed
        // deletion behind, so discovery still refuses the object.
        $id = $this->schedule($entity, $this->identities);
        unset($this->tombstones[$entity]);
        $this->inserts[spl_object_id($entity)] = [$entity, $id];
    }

    /**
     * Schedules a managed entity and the aggregate below it for deletion,
     * and detaches every entity awaiting insert it reaches, without SQL.
     *
     * An owned relationship this manager did not load is refused: skipping
     * it would leave rows the ownership mapping promises to remove, and
     * loading it would be I/O no property access performs.
     *
     * @throws InvalidEntityStateException for an object this manager does not
     *         hold, and for an owned relationship it never loaded
     */
    public function remove(object $entity): void
    {
        $this->assertUsable();

        if (!isset($this->snapshots[$entity]) && !isset($this->inserts[spl_object_id($entity)])) {
            throw InvalidEntityStateException::notHeld($entity::class);
        }

        [$deletes, $cancels] = $this->aggregate($entity, $this->inserts);

        foreach ($cancels as $cancelled) {
            $this->cancel($cancelled);
        }

        foreach ($deletes as $managed) {
            $this->removals[$managed] = true;
        }
    }

    /**
     * Writes the whole graph in one transaction: every scheduled insert, the
     * new entities one pass across initialized owned relationships reaches,
     * every change to a managed entity, every deletion an owned relationship
     * calls for, every scheduled deletion, and every join row an owning
     * #[ManyToMany] collection adds, drops or leaves behind. Every entity is
     * read, validated and ordered before any statement; with nothing to
     * write, nothing runs. A manager from open() begins the transaction on
     * the factory's client and commits it. A bound manager writes on its
     * transaction and leaves COMMIT to the factory. The package README's
     * "Aggregates", "Many-to-many relationships", "Flushing" and
     * "Transaction sessions" state the statements, the row checks and the
     * failure contract.
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
            [$nodes, $applied] = $this->prepare();

            if ($nodes === []) {
                return;
            }

            if ($this->link instanceof SqlTransaction) {
                $this->flushed = [$nodes, $this->write($this->link, $nodes), $applied];
            } else {
                $this->commit($nodes, $applied);
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
            [$nodes, $generated, $applied] = $this->flushed;
            $this->apply($nodes, $generated, $applied);
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
     * inverse relationship loads as loadInverse() states, and a
     * #[ManyToMany] as loadJoin() does.
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
            $join = $plan->join($property);

            if ($join !== null) {
                /** @var array<string, array<array-key, mixed>> $below */
                $this->eager($target, $this->loadJoin($plan, $target, $entities, $join), $below);

                continue;
            }

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
     * next level: every target assigned, or held by an initialized one. An
     * owned relationship it assigns also records that membership, which is
     * what flush() reconciles a later one against; an initialized one records
     * none, because the database state behind it is unknown.
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

            // What the database held through an owned relationship is the one
            // membership flush() may reconcile a later one against.
            if ($inverse['owned']) {
                $this->baseline($entity, $property, $loaded);
            }

            foreach ($loaded as $held) {
                $next[spl_object_id($held)] = $held;
            }
        }

        return array_values($next);
    }

    /**
     * Loads the join collection $join into $entities and returns the next
     * level: every target assigned, or held by an initialized one. An owning
     * collection it assigns also records that membership, which is what
     * flush() diffs a later one against; an inverse one records none,
     * because only the owning side writes the table.
     *
     * An initialized collection is never overwritten, and every value it
     * holds must be a target this manager manages. Every other entity takes
     * its identifier from its snapshot. The distinct identifiers are
     * selected from the join table against this end's column,
     * RELATIONSHIP_BATCH per statement and ordered by both columns, then the
     * distinct targets those rows name are selected from the target table,
     * RELATIONSHIP_BATCH per statement and ordered by its identifier. Each
     * target row goes through register(), which refuses a manager closed
     * during that statement before anything is assigned, and the join rows
     * are checked for the same closure.
     *
     * @param EntityPlan<object> $plan
     * @param EntityPlan<object> $target
     * @param list<object> $entities
     * @param JoinMapping $join
     * @return list<object>
     * @throws InvalidEntityStateException
     * @throws MappingException
     */
    private function loadJoin(EntityPlan $plan, EntityPlan $target, array $entities, array $join): array
    {
        $property = $join['name'];
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

            foreach ($this->members($entity, $property, $target->class) as $held) {
                $next[spl_object_id($held)] = isset($this->snapshots[$held])
                    ? $held
                    : throw InvalidEntityStateException::relationTargetNotHeld($plan->class, $property);
            }
        }

        $pairs = [];

        foreach (array_chunk(array_unique($keys), self::RELATIONSHIP_BATCH) as $batch) {
            $rows = new Query($this->link)
                ->table($join['table'])
                ->select($join['joinColumn'], $join['inverseJoinColumn'])
                ->whereIn($join['joinColumn'], $batch)
                ->orderBy($join['joinColumn'])
                ->orderBy($join['inverseJoinColumn'])
                ->get();
            // Checked after the SQL, as register() is: the Fiber may have
            // suspended there while the unit of work was closed.
            $this->assertUsable();

            foreach ($rows as $row) {
                $pairs[] = [
                    $plan->identifier(self::joinKey($plan, $join, $row, $join['joinColumn'])),
                    $target->identifier(self::joinKey($plan, $join, $row, $join['inverseJoinColumn'])),
                ];
            }
        }

        $found = [];

        foreach (array_chunk(array_unique(array_column($pairs, 1)), self::RELATIONSHIP_BATCH) as $batch) {
            $rows = $this->select($target)->whereIn($target->column($target->id), $batch)->orderBy($target->column($target->id))->get();

            foreach ($this->register($target, $rows) as [$id, $loaded]) {
                $found[$id] = $loaded;
            }
        }

        $members = [];

        foreach ($pairs as [$owner, $id]) {
            $members[$owner][] = $found[$id]
                ?? throw MappingException::missingJoinTarget($plan->class, $property, $join['table'], $target->class);
        }

        foreach ($unloaded as [$entity, $key]) {
            $loaded = $members[$key] ?? [];
            $plan->assign($entity, $property, $loaded);

            // What the join table held through the owning side is the one
            // membership flush() may diff a later one against.
            if ($join['mappedBy'] === null) {
                $this->baseline($entity, $property, $loaded);
            }

            foreach ($loaded as $held) {
                $next[spl_object_id($held)] = $held;
            }
        }

        return array_values($next);
    }

    /**
     * One end of a join row, which both join columns hold as a NOT NULL
     * foreign key.
     *
     * @param EntityPlan<object> $plan
     * @param JoinMapping $join
     * @param array<string, mixed> $row
     * @throws MappingException
     */
    private static function joinKey(EntityPlan $plan, array $join, array $row, string $column): int|string
    {
        $value = $row[$column] ?? null;

        return is_int($value) || is_string($value)
            ? $value
            : throw MappingException::invalidJoinRow($plan->class, $join['name'], $join['table'], $column);
    }

    /** A relationship target's identifier in this manager's snapshot, or null when it does not manage the target. */
    private function heldIdentifier(object $target): int|string|null
    {
        $snapshot = $this->snapshots[$target] ?? null;

        /** @var int|string|null an identifier's database value is an int or a string */
        return $snapshot === null ? null : $snapshot[$this->plans[$target::class]->id];
    }

    /**
     * A relationship target's foreign-key value while an entity is being
     * scheduled: a managed target's identifier, or a Reference standing for
     * any other entity of this factory's metadata, whose place in the graph
     * flush() decides. An object that is no entity at all has none.
     */
    private function admit(object $target): int|string|Reference|null
    {
        return $this->heldIdentifier($target)
            ?? (isset($this->plans[$target::class]) ? new Reference(spl_object_id($target)) : null);
    }

    /**
     * Validates an entity this manager does not hold, as persist() does, and
     * returns the identifier it is scheduled with: null when the database
     * generates it. An assigned identity joins $identities, so the same pass
     * refuses a second object for it.
     *
     * @param array<class-string, array<int|string, object>> $identities
     * @throws InvalidEntityStateException
     * @throws MappingException for a property value its type does not admit
     */
    private function schedule(object $entity, array &$identities): int|string|null
    {
        $plan = $this->plans[$entity::class];
        /** @var int|string|null $id an identifier property is typed int or string */
        $id = $plan->extract($entity, null, $this->admit(...))[$plan->id];

        if ($plan->generated) {
            return $id === null ? null : throw InvalidEntityStateException::generatedIdentifierSet($plan->class);
        }

        if ($id === null) {
            throw InvalidEntityStateException::nullIdentifier($plan->class);
        }

        if (isset($identities[$plan->class][$id])) {
            throw InvalidEntityStateException::identityConflict($plan->class);
        }

        $identities[$plan->class][$id] = $entity;

        return $id;
    }

    /** Drops an entity awaiting insert and the identity an assigned one took. */
    private function cancel(object $entity): void
    {
        $id = $this->inserts[spl_object_id($entity)][1] ?? null;
        unset($this->inserts[spl_object_id($entity)]);

        if ($id !== null) {
            unset($this->identities[$entity::class][$id]);
        }
    }

    /**
     * Cancels the deletion of a managed entity and of every managed entity
     * below it through an initialized owned relationship, so persist()
     * restores exactly the aggregate remove() scheduled. The whole subgraph
     * is collected and validated before the first deletion is cancelled, so
     * a refusal anywhere leaves every scheduled deletion as it was.
     *
     * @throws InvalidEntityStateException
     */
    private function keep(object $entity): void
    {
        $queue = [$entity];
        $seen = [spl_object_id($entity) => true];

        for ($i = 0; isset($queue[$i]); $i++) {
            foreach ($this->children($queue[$i], false) as $child) {
                $key = spl_object_id($child);

                if (!isset($seen[$key]) && isset($this->snapshots[$child])) {
                    $seen[$key] = true;
                    $queue[] = $child;
                }
            }
        }

        foreach ($queue as $owner) {
            unset($this->removals[$owner]);
        }
    }

    /**
     * The aggregate below $entity: every managed entity to delete, and every
     * entity awaiting insert whose insert is cancelled. Iterative and
     * cycle-safe, and atomic — it changes nothing, so a refusal anywhere
     * leaves the unit of work as it was.
     *
     * @param array<int, array{object, int|string|null}> $inserts the entities awaiting insert to cancel from
     * @return array{list<object>, list<object>}
     * @throws InvalidEntityStateException for an owned relationship of a managed entity this manager never loaded
     */
    private function aggregate(object $entity, array $inserts): array
    {
        $queue = [$entity];
        $seen = [spl_object_id($entity) => true];
        $deletes = [];
        $cancels = [];

        for ($i = 0; isset($queue[$i]); $i++) {
            $owner = $queue[$i];
            $managed = isset($this->snapshots[$owner]);

            if ($managed) {
                $deletes[] = $owner;
            } elseif (isset($inserts[spl_object_id($owner)])) {
                $cancels[] = $owner;
            } else {
                // Nothing this manager holds, so nothing below it is
                // scheduled either.
                continue;
            }

            foreach ($this->children($owner, $managed) as $child) {
                $key = spl_object_id($child);

                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $queue[] = $child;
                }
            }
        }

        return [$deletes, $cancels];
    }

    /**
     * Everything $owner holds through its owned relationships, in mapped
     * declaration and array order. $loaded demands that this manager loaded
     * each of them: without that membership the database state behind the
     * relationship is unknown, and neither skipping it nor reading it here
     * is something an aggregate operation may do.
     *
     * @return list<object>
     * @throws InvalidEntityStateException
     */
    private function children(object $owner, bool $loaded): array
    {
        $plan = $this->plans[$owner::class];
        $children = [];

        foreach ($plan->owned() as $property => $inverse) {
            if (!$plan->initialized($owner, $property)) {
                if ($loaded) {
                    throw InvalidEntityStateException::ownedRelationNotLoaded($plan->class, $property);
                }

                continue;
            }

            if ($loaded && !isset(($this->relations[$owner] ?? [])[$property])) {
                throw InvalidEntityStateException::ownedRelationNotLoaded($plan->class, $property);
            }

            foreach ($this->members($owner, $property, $inverse['target']) as $child) {
                $children[] = $child;
            }
        }

        return $children;
    }

    /**
     * What an initialized relationship holds, in array order, without a
     * #[HasOne]'s null.
     *
     * @param class-string $target
     * @return list<object>
     * @throws InvalidEntityStateException for a value outside the target class
     */
    private function members(object $owner, string $property, string $target): array
    {
        $plan = $this->plans[$owner::class];
        $related = $plan->related($owner, $property);
        $members = [];

        foreach (is_array($related) ? array_values($related) : [$related] as $member) {
            if ($member === null) {
                continue;
            }

            $members[] = $member instanceof $target
                ? $member
                : throw InvalidEntityStateException::relationTargetNotHeld($plan->class, $property);
        }

        return $members;
    }

    /**
     * Records the membership an owned relationship was loaded or written
     * with: the one state this manager may reconcile a later membership
     * against.
     *
     * @param list<object> $members
     */
    private function baseline(object $owner, string $property, array $members): void
    {
        $identifiers = [];

        foreach ($members as $member) {
            $identifier = $this->heldIdentifier($member);

            if ($identifier !== null) {
                $identifiers[] = $identifier;
            }
        }

        $relations = $this->relations[$owner] ?? [];
        $relations[$property] = $identifiers;
        $this->relations[$owner] = $relations;
    }

    /**
     * Every entity a flush reads, in one order: those awaiting insert in
     * scheduling order, then the managed ones in the order the identity map
     * took them.
     *
     * @param array<int, array{object, int|string|null}> $inserts
     * @return list<object>
     */
    private function roots(array $inserts): array
    {
        $roots = [];

        foreach ($inserts as [$entity]) {
            $roots[] = $entity;
        }

        foreach ($this->identities as $entities) {
            foreach ($entities as $entity) {
                if (isset($this->snapshots[$entity])) {
                    $roots[] = $entity;
                }
            }
        }

        return $roots;
    }

    /**
     * Everything one flush writes, computed without changing anything: the
     * ordered statements, the owned memberships COMMIT records, and the
     * inserts an orphaned aggregate cancels. A refusal here leaves the unit
     * of work exactly as it was, so the flush can run again once its cause
     * is fixed.
     *
     * @return array{list<PlanNode>, Applied}
     * @throws InvalidEntityStateException
     * @throws MappingException
     */
    private function prepare(): array
    {
        $inserts = $this->discover();
        $identify = fn (object $target): int|string|Reference|null => $this->heldIdentifier($target)
            ?? (isset($inserts[spl_object_id($target)]) ? new Reference(spl_object_id($target)) : null);
        $values = [];

        foreach ($inserts as $key => [$entity, $id]) {
            $plan = $this->plans[$entity::class];
            $values[$key] = $plan->extract($entity, null, $identify);

            if ($values[$key][$plan->id] !== $id) {
                throw InvalidEntityStateException::identifierChanged($plan->class);
            }
        }

        $managed = [];
        $removals = [];

        foreach ($this->identities as $class => $entities) {
            $plan = $this->plans[$class];

            foreach ($entities as $entity) {
                $snapshot = $this->snapshots[$entity] ?? null;

                if ($snapshot === null) {
                    continue;
                }

                $key = spl_object_id($entity);
                $values[$key] = $plan->extract($entity, $snapshot, $identify);

                if ($values[$key][$plan->id] !== $snapshot[$plan->id]) {
                    throw InvalidEntityStateException::identifierChanged($plan->class);
                }

                if ($plan->version !== null && $values[$key][$plan->version] !== $snapshot[$plan->version]) {
                    throw InvalidEntityStateException::versionChanged($plan->class);
                }

                $managed[] = $entity;

                if (isset($this->removals[$entity])) {
                    $removals[$key] = $entity;
                }
            }
        }

        [$relations, $orphans, $cancelled] = $this->reconcile($inserts, $values, $removals);
        $removals += $orphans;
        $scheduled = [];

        foreach ($inserts as $key => [$entity]) {
            if (!isset($cancelled[$key])) {
                $scheduled[] = ['entity' => $entity, 'plan' => $this->plans[$entity::class], 'values' => $values[$key]];
            }
        }

        [$links, $unlinks, $joined, $changed] = $this->joins($inserts, $removals, $cancelled);
        [$updates, $deletes] = $this->writes($managed, $values, $removals, $changed);

        return [
            FlushPlanner::plan($scheduled, $updates, $deletes, $links, $unlinks),
            ['relations' => [...$relations, ...$joined], 'cancelled' => array_values($cancelled)],
        ];
    }

    /**
     * Every join row this flush writes, the membership COMMIT records for
     * each owning collection it read, and the managed owners whose
     * membership it changes. Nothing here writes or removes a target entity:
     * a join row is the link alone.
     *
     * An owner this flush deletes loses every join row naming it, in one
     * DELETE by its join column and without reading the collection. Any other
     * owner's initialized collection is the state to reach: a new owner's is
     * every link to insert, and a managed owner's is diffed against the
     * membership this manager loaded, which is the only one it may diff
     * against. Reordering the array reaches the same state and writes
     * nothing.
     *
     * @param array<int, array{object, int|string|null}> $inserts
     * @param array<int, object> $removals every entity this flush deletes, by object id
     * @param array<int, object> $cancelled the inserts an orphaned aggregate cancels, by object id
     * @return array{list<LinkAction>, list<LinkAction>, list<array{object, string, list<object>}>, array<int, true>}
     * @throws InvalidEntityStateException
     */
    private function joins(array $inserts, array $removals, array $cancelled): array
    {
        $links = [];
        $unlinks = [];
        $records = [];
        $changed = [];

        foreach ($this->roots($inserts) as $owner) {
            $key = spl_object_id($owner);

            if (isset($cancelled[$key])) {
                continue;
            }

            $plan = $this->plans[$owner::class];
            /** @var int|string|Reference $ownerKey a root is managed or awaiting insert */
            $ownerKey = $this->heldIdentifier($owner) ?? new Reference($key);

            foreach ($plan->owningJoins() as $property => $join) {
                $action = ['plan' => $plan, 'property' => $property, 'table' => $join['table']];

                if (isset($removals[$key])) {
                    $unlinks[] = [...$action, 'values' => [$join['joinColumn'] => $ownerKey]];

                    continue;
                }

                if (!$plan->initialized($owner, $property)) {
                    continue;
                }

                $baseline = ($this->relations[$owner] ?? [])[$property] ?? null;

                if ($baseline === null && isset($this->snapshots[$owner])) {
                    throw InvalidEntityStateException::joinCollectionNotLoaded($plan->class, $property);
                }

                [$members, $present, $endpoints] = $this->endpoints($owner, $property, $join, $inserts);
                $written = [count($unlinks), count($links)];

                foreach ($baseline ?? [] as $identifier) {
                    if (!in_array($identifier, $present, true)) {
                        $unlinks[] = [...$action, 'values' => [
                            $join['joinColumn'] => $ownerKey,
                            $join['inverseJoinColumn'] => $identifier,
                        ]];
                    }
                }

                foreach ($endpoints as $i => $endpoint) {
                    if ($baseline === null || $present[$i] === null || !in_array($present[$i], $baseline, true)) {
                        $links[] = [...$action, 'values' => [
                            $join['joinColumn'] => $ownerKey,
                            $join['inverseJoinColumn'] => $endpoint,
                        ]];
                    }
                }

                // A membership this flush changes is a change of the owner's
                // own state that no column of its row carries, so a versioned
                // owner locks it through its version like any other change.
                if ($baseline !== null && [count($unlinks), count($links)] !== $written) {
                    $changed[$key] = true;
                }

                $records[] = [$owner, $property, $members];
            }
        }

        return [$links, $unlinks, $records, $changed];
    }

    /**
     * What an owning join collection holds: its targets, the identifier each
     * one already has, and the value a join row names it by — a Reference
     * where an insert of this flush generates that identifier. One link is
     * one join row and this manager holds one object per row, so an object
     * appearing twice is a duplicate row.
     *
     * @param JoinMapping $join
     * @param array<int, array{object, int|string|null}> $inserts
     * @return array{list<object>, list<int|string|null>, list<int|string|Reference>}
     * @throws InvalidEntityStateException
     */
    private function endpoints(object $owner, string $property, array $join, array $inserts): array
    {
        $plan = $this->plans[$owner::class];
        $members = [];
        $present = [];
        $endpoints = [];
        $seen = [];

        foreach ($this->members($owner, $property, $join['target']) as $member) {
            $key = spl_object_id($member);
            $identifier = $this->heldIdentifier($member) ?? ($inserts[$key][1] ?? null);

            if (isset($seen[$key])) {
                throw InvalidEntityStateException::duplicateJoinTarget($plan->class, $property, $join['target']);
            }

            $seen[$key] = true;
            $members[] = $member;
            $present[] = $identifier;
            $endpoints[] = $this->heldIdentifier($member) ?? (isset($inserts[$key])
                ? new Reference($key)
                : throw InvalidEntityStateException::relationTargetNotHeld($plan->class, $property));
        }

        return [$members, $present, $endpoints];
    }

    /**
     * Each managed entity's DELETE, or the UPDATE of its changed columns and
     * the next version a versioned entity takes. A versioned owner whose
     * owning join membership this flush changes takes that next version too:
     * through the UPDATE its changed columns already send, or through one
     * that writes the version column alone.
     *
     * @param list<object> $managed
     * @param array<int, PlanValues> $values
     * @param array<int, object> $removals
     * @param array<int, true> $changed the owners whose owning join membership this flush changes, by object id
     * @return array{list<UpdateAction>, list<DeleteAction>}
     * @throws InvalidEntityStateException
     */
    private function writes(array $managed, array $values, array $removals, array $changed): array
    {
        $updates = [];
        $deletes = [];

        foreach ($managed as $entity) {
            $key = spl_object_id($entity);
            $plan = $this->plans[$entity::class];
            $snapshot = $this->snapshots[$entity];
            /** @var int|string $id a managed entity's snapshot holds its identifier */
            $id = $snapshot[$plan->id];
            /** @var int|null $version a version property is typed int */
            $version = $plan->version === null ? null : $snapshot[$plan->version];

            if (isset($removals[$key])) {
                $deletes[] = ['entity' => $entity, 'plan' => $plan, 'id' => $id, 'version' => $version, 'snapshot' => $snapshot];

                continue;
            }

            $changes = array_filter(
                $values[$key],
                static fn (mixed $value, string $name): bool => $value !== $snapshot[$name],
                ARRAY_FILTER_USE_BOTH,
            );

            $joins = $plan->version !== null && isset($changed[$key]);

            if ($changes === [] && !$joins) {
                continue;
            }

            // The version is unchanged, so it is not among $changes: the
            // UPDATE sets the next one, which the snapshot takes on COMMIT.
            // A join membership alone leaves it the only column to write.
            if ($plan->version !== null) {
                /** @var int $version set above for a versioned entity */
                $changes[$plan->version] = $values[$key][$plan->version] = $version !== PHP_INT_MAX
                    ? $version + 1
                    : throw InvalidEntityStateException::versionExhausted($plan->class);
            }

            $updates[] = [
                'entity' => $entity,
                'plan' => $plan,
                'id' => $id,
                'version' => $version,
                'snapshot' => $snapshot,
                'values' => $values[$key],
                'changes' => $changes,
                'joins' => $joins,
            ];
        }

        return [$updates, $deletes];
    }

    /**
     * One pass across every initialized owned relationship, from the
     * entities awaiting insert in scheduling order and then the managed
     * ones, depth first in mapped declaration and array order, scheduling
     * every new entity it reaches as persist() would.
     *
     * An uninitialized relationship is skipped and never loaded: an
     * uninitialized property holds no object, so no in-memory entity is
     * lost. An object whose deletion this manager committed is refused
     * here rather than inserted again.
     *
     * Nothing is scheduled on this manager: the pass returns the entities
     * awaiting insert the flush will write, and COMMIT records them.
     *
     * @return array<int, array{object, int|string|null}>
     * @throws InvalidEntityStateException
     * @throws MappingException
     */
    private function discover(): array
    {
        $inserts = $this->inserts;
        $identities = $this->identities;
        $roots = $this->roots($inserts);
        $seen = [];

        foreach ($roots as $root) {
            $seen[spl_object_id($root)] = true;
        }

        // A stack, taken from the top and filled in reverse, walks the graph
        // depth first without growing the call stack.
        $stack = array_reverse($roots);

        while ($stack !== []) {
            $owner = array_pop($stack);
            $key = spl_object_id($owner);

            if (!isset($this->snapshots[$owner]) && !isset($inserts[$key])) {
                $inserts[$key] = [$owner, $this->schedule($owner, $identities)];
            }

            $plan = $this->plans[$owner::class];
            $children = [];

            foreach ($plan->owned() as $property => $inverse) {
                if (!$plan->initialized($owner, $property)) {
                    continue;
                }

                foreach ($this->members($owner, $property, $inverse['target']) as $child) {
                    if (isset($this->tombstones[$child])) {
                        throw InvalidEntityStateException::deletedChildRediscovered($plan->class, $property, $inverse['target']);
                    }

                    if (!isset($seen[spl_object_id($child)])) {
                        $seen[spl_object_id($child)] = true;
                        $children[] = $child;
                    }
                }
            }

            foreach (array_reverse($children) as $child) {
                $stack[] = $child;
            }
        }

        return $inserts;
    }

    /**
     * Checks every initialized owned relationship against the graph the
     * flush will write, and diffs the ones this manager loaded.
     *
     * A target it holds must name its owner through its own #[BelongsTo]
     * property, must appear once, and must appear under one owner. A target
     * the loaded membership held and the relationship no longer does is an
     * orphan when its foreign key still names this owner or is null, and its
     * whole aggregate is deleted; when the foreign key names another owner
     * the ordinary UPDATE moves it, and a loaded relationship on that owner
     * must hold it. Nothing here writes an owner column or advances an
     * owner's version.
     *
     * @param array<int, array{object, int|string|null}> $inserts
     * @param array<int, PlanValues> $values every scheduled entity's extracted values, by object id
     * @param array<int, object> $removals managed entities already scheduled for deletion, by object id
     * @return array{list<array{object, string, list<object>}>, array<int, object>, array<int, object>}
     * @throws InvalidEntityStateException
     */
    private function reconcile(array $inserts, array $values, array $removals): array
    {
        $holder = [];
        $loaded = [];
        $records = [];

        foreach ($this->roots($inserts) as $owner) {
            $plan = $this->plans[$owner::class];

            foreach ($plan->owned() as $property => $inverse) {
                if (!$plan->initialized($owner, $property)) {
                    continue;
                }

                $members = [];
                $present = [];

                foreach ($this->members($owner, $property, $inverse['target']) as $child) {
                    $key = spl_object_id($child);
                    $identifier = $this->heldIdentifier($child) ?? ($inserts[$key][1] ?? null);

                    if (isset($holder[$key]) || ($identifier !== null && in_array($identifier, $present, true))) {
                        throw ($holder[$key] ?? $owner) === $owner
                            ? InvalidEntityStateException::duplicateOwnedChild($plan->class, $property, $inverse['target'])
                            : InvalidEntityStateException::ownedChildShared($plan->class, $property, $inverse['target']);
                    }

                    if (!$this->owns($owner, $child, $inverse['mappedBy'], $values)) {
                        throw InvalidEntityStateException::ownedChildElsewhere($plan->class, $property, $inverse['target']);
                    }

                    $holder[$key] = $owner;
                    $members[] = $child;

                    if ($identifier !== null) {
                        $present[] = $identifier;
                    }
                }

                $baseline = ($this->relations[$owner] ?? [])[$property] ?? null;

                // A new owner's relationship is its complete membership, so
                // COMMIT makes it the baseline. A managed one this manager
                // never loaded gets none: what the database holds through it
                // stays unknown.
                if ($baseline !== null || !isset($this->snapshots[$owner])) {
                    $records[] = [$owner, $property, $members];
                }

                if ($baseline !== null) {
                    $loaded[] = [$owner, $property, $inverse, $baseline, $present];
                }
            }
        }

        $orphans = [];
        $cancelled = [];

        foreach ($loaded as [$owner, $property, $inverse, $baseline, $present]) {
            $plan = $this->plans[$owner::class];

            foreach ($baseline as $identifier) {
                if (in_array($identifier, $present, true)) {
                    continue;
                }

                $child = $this->identities[$inverse['target']][$identifier] ?? null;

                if ($child === null || !isset($this->snapshots[$child])) {
                    continue;
                }

                if ($this->owns($owner, $child, $inverse['mappedBy'], $values)
                    || $values[spl_object_id($child)][$inverse['mappedBy']] === null) {
                    [$deletes, $cancels] = $this->aggregate($child, $inserts);

                    foreach ($deletes as $managed) {
                        $orphans[spl_object_id($managed)] = $managed;
                    }

                    foreach ($cancels as $pending) {
                        $cancelled[spl_object_id($pending)] = $pending;
                    }

                    continue;
                }

                $next = $this->owner($plan, $values[spl_object_id($child)][$inverse['mappedBy']], $inserts);

                if ($next !== null && $this->watches($next, $property) && ($holder[spl_object_id($child)] ?? null) !== $next) {
                    throw InvalidEntityStateException::reparentedChildMissing($plan->class, $property, $inverse['target']);
                }
            }
        }

        return [$records, $orphans + $removals, $cancelled];
    }

    /**
     * The entity a child's foreign key now names, or null when this manager
     * holds no object for it.
     *
     * @param EntityPlan<object> $plan the owner's mapping
     * @param array<int, array{object, int|string|null}> $inserts
     */
    private function owner(EntityPlan $plan, null|bool|int|float|string|Reference $key, array $inserts): ?object
    {
        if ($key instanceof Reference) {
            return $inserts[$key->entity][0] ?? null;
        }

        return is_int($key) || is_string($key) ? $this->identities[$plan->class][$key] ?? null : null;
    }

    /**
     * Whether $owner's relationship is one this flush reconciles: a new
     * owner's initialized relationship is its complete membership, and a
     * managed owner's counts once this manager loaded it.
     */
    private function watches(object $owner, string $property): bool
    {
        return $this->plans[$owner::class]->initialized($owner, $property)
            && (!isset($this->snapshots[$owner]) || isset(($this->relations[$owner] ?? [])[$property]));
    }

    /**
     * Whether the foreign key $child will hold names $owner. It reads the
     * extracted value, which is the child's snapshot foreign key when the
     * #[BelongsTo] property was never loaded, so dropping an eagerly loaded
     * child from a collection is a complete instruction on its own.
     *
     * @param array<int, PlanValues> $values
     */
    private function owns(object $owner, object $child, string $mappedBy, array $values): bool
    {
        $key = $values[spl_object_id($child)][$mappedBy] ?? null;

        return $key instanceof Reference
            ? $key->entity === spl_object_id($owner)
            : $key !== null && $key === $this->heldIdentifier($owner);
    }

    /**
     * @param list<PlanNode> $nodes
     * @param Applied $applied
     * @throws OptimisticLockException
     * @throws RollbackFailedException
     * @throws UnknownFlushOutcomeException
     */
    private function commit(array $nodes, array $applied): void
    {
        $transaction = $this->link->beginTransaction();
        $this->transaction = $transaction;
        $committing = false;

        try {
            $generated = $this->write($transaction, $nodes);
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
            $this->apply($nodes, $generated, $applied);
        }
    }

    /**
     * Runs every statement of the plan, in its order, and returns the
     * identifier each generated insert reported, by the object id of the
     * entity it inserted. Every later statement resolves its references from
     * those keys, and no entity is written. A link statement writes a join
     * table: its INSERT writes one row, and its DELETE may match any number.
     *
     * @param list<PlanNode> $nodes
     * @return array<int, int>
     * @throws OptimisticLockException
     */
    private function write(SqlTransaction $transaction, array $nodes): array
    {
        $this->assertOpen();
        $generated = [];

        foreach ($nodes as $node) {
            $values = self::resolve($node->sent, $generated);

            if ($node->table !== null) {
                $this->writeJoin($transaction, $node, $values);

                continue;
            }

            if ($node->kind === 'insert') {
                $key = $this->insert($transaction, $node, $values);

                if ($key !== null) {
                    /** @var object $entity an insert always writes one */
                    $entity = $node->entity;
                    $generated[spl_object_id($entity)] = $key;
                }

                continue;
            }

            $this->modify($transaction, $node, $values, $generated);
        }

        return $generated;
    }

    /**
     * Runs one join-table statement: an INSERT writing one row, or a DELETE
     * matching every row that holds the columns the statement names.
     *
     * @param Values $values
     */
    private function writeJoin(SqlTransaction $transaction, PlanNode $node, array $values): void
    {
        /** @var string $table a link statement names the join table it writes */
        $table = $node->table;
        $rows = new Query($transaction)->table($table);

        if ($node->kind === 'link') {
            $rows->insert($values);
        } else {
            foreach ($values as $column => $value) {
                $rows->where($column, '=', $value);
            }

            // A join row is a link alone, with no version and no row the
            // flush can claim: an owner keeps none, and a link already gone
            // is nothing left to delete.
            $rows->delete();
        }

        $this->assertOpen();
    }

    /**
     * Runs one INSERT and returns the identifier it reported, or null for a
     * row whose identifier the application assigned.
     *
     * @param Values $values
     */
    private function insert(SqlTransaction $transaction, PlanNode $node, array $values): ?int
    {
        $plan = $node->plan;
        $insert = new Query($transaction)->table($plan->table);

        if (!$plan->generated) {
            $insert->insert($plan->row($values));
            $this->assertOpen();

            return null;
        }

        unset($values[$plan->id]);
        $key = $insert->insertGetId($plan->row($values), $plan->column($plan->id));
        $this->assertOpen();

        return $plan->generatedIdentifier($key);
    }

    /**
     * Runs one statement matching a single row by its identifier — an UPDATE,
     * a fix-up or a DELETE — and reads the rows it affected: more than one is
     * an ambiguous identifier, and none is a stale version, a row another
     * writer removed, or an UPDATE whose row already held every value.
     *
     * @param Values $values
     * @param array<int, int> $generated
     * @throws InvalidEntityStateException
     * @throws OptimisticLockException
     */
    private function modify(SqlTransaction $transaction, PlanNode $node, array $values, array $generated): void
    {
        $plan = $node->plan;
        /** @var int|string $id a fix-up of a generated row resolves to the key its insert reported */
        $id = $node->id instanceof Reference ? $generated[$node->id->entity] : $node->id;
        $row = new Query($transaction)->table($plan->table)->where($plan->column($plan->id), '=', $id);

        if ($plan->version !== null && $node->version !== null) {
            $row->where($plan->column($plan->version), '=', $node->version);
        }

        $affected = $node->kind === 'delete' ? $row->delete() : $row->update($plan->row($values));
        // The MySQL family reports changed rows, not matched ones, so an
        // UPDATE writing the values its row already holds affects none. A
        // versioned UPDATE that matches always changes its version, so
        // none means the row is stale, and a fix-up always writes a key
        // the row does not hold yet.
        $exists = $affected === 0 && $node->kind === 'update' && $node->version === null
            && new Query($transaction)->table($plan->table)->where($plan->column($plan->id), '=', $id)->exists();
        $this->assertOpen();
        $statement = $node->kind === 'delete' ? 'DELETE' : 'UPDATE';

        if ($affected > 1) {
            throw InvalidEntityStateException::ambiguousRow($plan->class, $statement, $affected);
        }

        if ($affected === 0 && $node->version !== null) {
            throw OptimisticLockException::stale($plan->class, $statement);
        }

        if ($affected === 0 && !$exists) {
            throw InvalidEntityStateException::missingRow($plan->class, $statement);
        }
    }

    /**
     * Snapshots every written entity with the values the flush sent, not
     * whatever its properties hold by now, and writes each inserted or
     * updated entity's version and each generated identifier: the flush owns
     * them, so a change made meanwhile is overwritten. A deleted entity is
     * detached behind a tombstone, every reconciled owned relationship and
     * every written join collection takes the membership the plan was built
     * from, and every membership this manager still holds loses the rows the
     * flush deleted.
     *
     * @param list<PlanNode> $nodes
     * @param array<int, int> $generated
     * @param Applied $applied
     */
    private function apply(array $nodes, array $generated, array $applied): void
    {
        foreach ($applied['cancelled'] as $entity) {
            $this->cancel($entity);
        }

        $deleted = [];

        foreach ($nodes as $node) {
            $plan = $node->plan;
            $entity = $node->entity;

            if ($entity === null) {
                // A fix-up completes the row an insert or a delete owns, and
                // a link statement writes a join row no entity holds.
                continue;
            }

            if ($node->kind === 'delete') {
                /** @var int|string $id a delete names a managed row */
                $id = $node->id;
                $deleted[$plan->class][$id] = true;
                unset(
                    $this->identities[$plan->class][$id],
                    $this->snapshots[$entity],
                    $this->removals[$entity],
                    $this->relations[$entity],
                );
                $this->tombstones[$entity] = true;

                continue;
            }

            /** @var PlanValues $planned an insert and an update both carry them */
            $planned = $node->values;
            $values = self::resolve($planned, $generated);

            if ($node->kind === 'insert') {
                if ($plan->generated) {
                    $values[$plan->id] = $generated[spl_object_id($entity)];
                    $plan->assign($entity, $plan->id, $generated[spl_object_id($entity)]);
                }

                /** @var int|string $identity an identifier is an int or a string by now */
                $identity = $values[$plan->id];
                $this->identities[$plan->class][$identity] = $entity;
                unset($this->inserts[spl_object_id($entity)]);
            }

            if ($plan->version !== null) {
                /** @var int $version a version property is typed int */
                $version = $values[$plan->version];
                $plan->assign($entity, $plan->version, $version);
            }

            $this->snapshots[$entity] = $values;
        }

        foreach ($applied['relations'] as [$owner, $property, $members]) {
            if (isset($this->snapshots[$owner])) {
                $this->baseline($owner, $property, $members);
            }
        }

        $this->prune($deleted);
    }

    /**
     * Drops every row the flush deleted from the memberships this manager
     * still holds, so a loaded relationship never names a deleted row.
     *
     * @param array<class-string, array<int|string, true>> $deleted
     */
    private function prune(array $deleted): void
    {
        if ($deleted === []) {
            return;
        }

        $pruned = [];

        foreach ($this->relations as $owner => $relations) {
            $plan = $this->plans[$owner::class];
            $changed = false;

            foreach ($relations as $property => $identifiers) {
                $target = $plan->target($property);
                $kept = array_values(array_filter(
                    $identifiers,
                    static fn (int|string $identifier): bool => !isset($deleted[$target][$identifier]),
                ));

                if ($kept !== $identifiers) {
                    $relations[$property] = $kept;
                    $changed = true;
                }
            }

            if ($changed) {
                $pruned[] = [$owner, $relations];
            }
        }

        foreach ($pruned as [$owner, $relations]) {
            $this->relations[$owner] = $relations;
        }
    }

    /**
     * @param PlanValues $values
     * @param array<int, int> $generated
     * @return Values
     */
    private static function resolve(array $values, array $generated): array
    {
        foreach ($values as $name => $value) {
            if ($value instanceof Reference) {
                $values[$name] = $generated[$value->entity];
            }
        }

        /** @var Values $values every Reference names an insert the plan ran first */
        return $values;
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
        $this->relations = new WeakMap();
        $this->tombstones = new WeakMap();
        $this->flushed = null;
    }
}
