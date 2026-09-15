<?php

declare(strict_types=1);

namespace Kinetis\Orm;

use Kinetis\Orm\Exception\InvalidEntityStateException;
use Kinetis\Orm\Exception\MappingException;
use Kinetis\QueryBuilder\CursorPaginator;
use Kinetis\QueryBuilder\LockWait;
use Kinetis\QueryBuilder\Paginator;
use Kinetis\QueryBuilder\Query;

/**
 * A select of one entity class, over a fresh Kinetis\QueryBuilder\Query
 * that reads every mapped column. Predicates, order and cursor name mapped
 * properties: each resolves to its column, and each predicate value is
 * converted through the property's type before it reaches Query, so an
 * unknown property or an inadmissible value throws MappingException before
 * SQL runs. The terminals return managed entities.
 *
 * with() names the relationships get(), first(), paginate() and
 * cursorPaginate() load into those entities after the root select, each
 * through its own selects on the same link. A lock applies to the root
 * select only. exists() and count() load nothing. An inverse relationship
 * maps no column, so no predicate, order or cursor names it.
 *
 * Every terminal checks its EntityManager again once its SQL returns, so a
 * manager closed while the Fiber was suspended in that SQL is refused
 * rather than answered. In a manager OrmFactory::transaction() bound, what
 * a terminal throws from Query onward — a refusal Query raises before SQL
 * included — fails the whole session.
 *
 * Like Query, one instance accumulates one query.
 *
 * @template T of object
 */
final class EntityQuery
{
    private readonly Query $query;

    /** @var array<string, array<array-key, mixed>> property => the relationships to load below it */
    private array $relations = [];

    /**
     * @internal EntityRepository::query() creates every instance.
     *
     * @param EntityPlan<T> $plan
     */
    public function __construct(
        private readonly EntityManager $manager,
        private readonly EntityPlan $plan,
    ) {
        $this->query = $manager->select($plan);
    }

    /**
     * A null $value follows Query::where(): `=` compiles to IS NULL, `!=`
     * and `<>` to IS NOT NULL, and any other operator is refused.
     *
     * @return $this
     * @throws MappingException
     */
    public function where(string $property, string $operator, mixed $value): self
    {
        $this->manager->assertUsable();
        $this->query->where($this->plan->column($property), $operator, $this->plan->parameter($property, $value));

        return $this;
    }

    /**
     * @param array<array-key, mixed> $values
     * @return $this
     * @throws MappingException
     */
    public function whereIn(string $property, array $values): self
    {
        $this->manager->assertUsable();
        $column = $this->plan->column($property);
        $this->query->whereIn($column, array_map(
            fn (mixed $value): null|bool|int|float|string => $this->plan->parameter($property, $value),
            array_values($values),
        ));

        return $this;
    }

    /**
     * @return $this
     * @throws MappingException
     */
    public function orderBy(string $property, string $direction = 'ASC'): self
    {
        $this->manager->assertUsable();
        $this->query->orderBy($this->plan->column($property), $direction);

        return $this;
    }

    /**
     * @return $this
     */
    public function limit(int $limit): self
    {
        $this->manager->assertUsable();
        $this->query->limit($limit);

        return $this;
    }

    /**
     * @return $this
     */
    public function offset(int $offset): self
    {
        $this->manager->assertUsable();
        $this->query->offset($offset);

        return $this;
    }

    /**
     * Query::lockForUpdate(): the selected rows stay locked until the
     * transaction ends. Query decides which terminals, clauses and wait
     * modes a lock admits when a terminal runs.
     *
     * @return $this
     * @throws InvalidEntityStateException for a manager not bound by OrmFactory::transaction()
     */
    public function lockForUpdate(LockWait $wait = LockWait::Wait): self
    {
        $this->manager->assertLockable();
        $this->query->lockForUpdate($wait);

        return $this;
    }

    /**
     * Query::lockForShare(), admitted as lockForUpdate() is.
     *
     * @return $this
     * @throws InvalidEntityStateException for a manager not bound by OrmFactory::transaction()
     */
    public function lockForShare(): self
    {
        $this->manager->assertLockable();
        $this->query->lockForShare();

        return $this;
    }

    /**
     * Relationship paths to load, such as `author`, `comments` or
     * `comments.author`: dot-separated #[BelongsTo], #[HasOne] and #[HasMany]
     * properties, each of the entity the one before it loads. Repeated calls
     * add to the same set, and every path is checked before this returns.
     *
     * @return $this
     * @throws MappingException for an empty segment, an unknown property or a property that is not a relationship
     */
    public function with(string ...$relations): self
    {
        $this->manager->assertUsable();
        $merged = $this->relations;

        foreach ($relations as $path) {
            $merged = $this->manager->mergeRelation($this->plan, $merged, $path);
        }

        $this->relations = $merged;

        return $this;
    }

    /**
     * Every matching entity, buffered in full.
     *
     * @return list<T>
     */
    public function get(): array
    {
        $this->manager->assertUsable();

        return $this->manager->terminal(fn (): array => $this->manager->load($this->plan, $this->query->get(), $this->relations));
    }

    /**
     * @return T|null
     */
    public function first(): ?object
    {
        $this->manager->assertUsable();

        return $this->manager->terminal(function (): ?object {
            $row = $this->query->first();

            if ($row === null) {
                $this->manager->assertUsable();

                return null;
            }

            return $this->manager->load($this->plan, [$row], $this->relations)[0];
        });
    }

    /** One statement: with() does not apply. */
    public function exists(): bool
    {
        $this->manager->assertUsable();

        return $this->manager->terminal(function (): bool {
            $exists = $this->query->exists();
            $this->manager->assertUsable();

            return $exists;
        });
    }

    /** One statement: with() does not apply. */
    public function count(): int
    {
        $this->manager->assertUsable();

        return $this->manager->terminal(function (): int {
            $count = $this->query->count();
            $this->manager->assertUsable();

            return $count;
        });
    }

    /**
     * Query::paginate()'s page, with its data loaded as managed entities.
     * Requires an orderBy().
     */
    public function paginate(int $perPage, int $page = 1): Paginator
    {
        $this->manager->assertUsable();

        return $this->manager->terminal(function () use ($perPage, $page): Paginator {
            $window = $this->query->paginate($perPage, $page);

            /** @var list<array<string, mixed>> $rows */
            $rows = $window->data;

            return new Paginator(
                data: $this->manager->load($this->plan, $rows, $this->relations),
                currentPage: $window->currentPage,
                perPage: $window->perPage,
                total: $window->total,
                lastPage: $window->lastPage,
            );
        });
    }

    /**
     * Query::cursorPaginate() over the column $property maps to, with its
     * data loaded as managed entities. The property must be unique and
     * strictly increasing, as Query requires of the cursor column.
     *
     * @throws MappingException
     */
    public function cursorPaginate(int $perPage, ?string $cursor, string $property = 'id'): CursorPaginator
    {
        $this->manager->assertUsable();
        $column = $this->plan->column($property);

        return $this->manager->terminal(function () use ($perPage, $cursor, $column): CursorPaginator {
            $page = $this->query->cursorPaginate($perPage, $cursor, $column);

            /** @var list<array<string, mixed>> $rows */
            $rows = $page->data;

            return new CursorPaginator($this->manager->load($this->plan, $rows, $this->relations), $page->nextCursor, $page->hasMore);
        });
    }

    /**
     * A copy of the underlying Query, for SQL this class does not express.
     * Changing the copy leaves this query unchanged, and its terminals
     * return arrays or DTOs that the EntityManager never manages.
     */
    public function builder(): Query
    {
        $this->manager->assertUsable();

        return clone $this->query;
    }
}
