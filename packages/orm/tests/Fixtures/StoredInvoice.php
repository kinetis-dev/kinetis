<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\Version;

/** A versioned entity over a BIGINT version column. */
#[Entity(table: 'kin_orm_invoices')]
final class StoredInvoice
{
    public int $id;

    public string $status;

    #[Version]
    public int $version;
}
