<?php

declare(strict_types=1);

namespace Kinetis\Orm\Flush;

use Kinetis\Orm\EntityPlan;

/**
 * @internal One statement of an ordered flush plan, immutable once
 *           FlushPlanner built it. A plan holds entity references only for
 *           the flush that runs it.
 *
 * @phpstan-import-type PlanValues from FlushPlanner
 * @psalm-import-type PlanValues from FlushPlanner
 */
final readonly class PlanNode
{
    /**
     * @param 'insert'|'update'|'delete'|'fixup'|'link'|'unlink' $kind
     * @param EntityPlan<object> $plan the entity the statement writes, or for
     *        a join-table statement the owning entity of its relationship
     * @param object|null $entity the entity the statement writes, null for a
     *        fix-up, which completes an insert or precedes a delete of a row
     *        another node owns, and for a join-table statement, which writes
     *        no entity
     * @param int|string|Reference|null $id the row's identifier: null for an
     *        insert and for every join-table statement, and a Reference for a
     *        fix-up of a row whose key this flush generates
     * @param int|null $version the version the statement matches, null for an
     *        unversioned entity and for every fix-up and join-table
     *        statement, which neither match nor advance one
     * @param PlanValues $sent the columns the statement writes, or for a
     *        join-table DELETE the columns it matches
     * @param PlanValues|null $values the snapshot the entity takes once
     *        COMMIT returns, null for a node that contributes none
     * @param string|null $table the join table a link statement writes, null
     *        for every entity statement, which writes its entity's table
     */
    public function __construct(
        public string $kind,
        public EntityPlan $plan,
        public ?object $entity,
        public int|string|Reference|null $id,
        public ?int $version,
        public array $sent,
        public ?array $values,
        public ?string $table = null,
    ) {}
}
