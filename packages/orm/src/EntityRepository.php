<?php

declare(strict_types=1);

namespace Kinetis\Orm;

use Kinetis\Orm\Exception\EntityNotFoundException;
use Kinetis\Orm\Exception\MappingException;

/**
 * Typed reads of one entity class through the EntityManager that created
 * it. An application repository wraps this class rather than extending it.
 *
 * @template T of object
 */
final readonly class EntityRepository
{
    /**
     * @internal EntityManager::repository() creates every instance.
     *
     * @param EntityPlan<T> $plan
     */
    public function __construct(
        private EntityManager $manager,
        private EntityPlan $plan,
    ) {}

    /**
     * The entity with this identifier, or null. $id is converted like the
     * identifier property first, and an identity the manager holds under
     * exactly that key returns without SQL. Otherwise the identifier column
     * is queried, and a returned row resolves through the identity map by
     * the identifier the database returned.
     *
     * @return T|null
     * @throws MappingException for an identifier its property does not admit
     */
    public function find(int|string $id): ?object
    {
        $this->manager->assertUsable();

        return $this->manager->managed($this->plan, $this->plan->identifier($id))
            ?? $this->query()->where($this->plan->id, '=', $id)->first();
    }

    /**
     * @return T
     * @throws EntityNotFoundException
     */
    public function findOrFail(int|string $id): object
    {
        return $this->find($id) ?? throw EntityNotFoundException::forClass($this->plan->class);
    }

    /**
     * Every entity whose properties equal $criteria, buffered in full; no
     * criteria reads the whole table.
     *
     * @param array<string, mixed> $criteria property => value
     * @return list<T>
     */
    public function findBy(array $criteria): array
    {
        $query = $this->query();

        foreach ($criteria as $property => $value) {
            $query->where((string) $property, '=', $value);
        }

        return $query->get();
    }

    /**
     * @return EntityQuery<T>
     */
    public function query(): EntityQuery
    {
        $this->manager->assertUsable();

        return new EntityQuery($this->manager, $this->plan);
    }
}
