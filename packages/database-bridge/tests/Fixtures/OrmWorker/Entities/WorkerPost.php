<?php

declare(strict_types=1);

namespace Kinetis\DatabaseBridge\Tests\Fixtures\OrmWorker\Entities;

use Kinetis\Orm\Attributes\Entity;

/**
 * OrmWorkerSequenceTest's own entity, mapped to a table name unmistakably
 * owned by this fixture rather than a generic one a shared database might
 * already use for application data.
 */
#[Entity(table: 'kinetis_orm_worker_posts')]
final class WorkerPost
{
    public int $id;

    public string $title;
}
