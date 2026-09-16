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
     * @param 'insert'|'update'|'delete'|'fixup' $kind
     * @param EntityPlan<object> $plan
     * @param object|null $entity the entity the statement writes, null for a
     *        fix-up, which completes an insert or precedes a delete of a row
     *        another node owns
     * @param int|string|Reference|null $id the row's identifier: null for an
     *        insert, and a Reference for a fix-up of a row whose key this
     *        flush generates
     * @param int|null $version the version the statement matches, null for an
     *        unversioned entity and for every fix-up, which neither matches
     *        nor advances one
     * @param PlanValues $sent the columns the statement writes
     * @param PlanValues|null $values the snapshot the entity takes once
     *        COMMIT returns, null for a node that contributes none
     */
    public function __construct(
        public string $kind,
        public EntityPlan $plan,
        public ?object $entity,
        public int|string|Reference|null $id,
        public ?int $version,
        public array $sent,
        public ?array $values,
    ) {}
}
