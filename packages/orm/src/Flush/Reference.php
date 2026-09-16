<?php

declare(strict_types=1);

namespace Kinetis\Orm\Flush;

/**
 * @internal A foreign key whose target identifier an earlier insert of the
 *           same flush generates. It stands in plan values only: no public
 *           metadata, entity property or snapshot ever holds one, and the
 *           executor resolves it from the keys its inserts reported.
 */
final readonly class Reference
{
    /**
     * @param int $entity spl_object_id() of the entity awaiting insert whose
     *        identifier this names. The unit of work holds that entity for
     *        the whole flush, so its object id cannot be reused.
     */
    public function __construct(public int $entity) {}
}
