<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\Column;
use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\Version;

/** A versioned entity whose version column is not named after its property. */
#[Entity(table: 'invoices')]
final class Invoice
{
    public int $id;

    public string $status;

    #[Version]
    #[Column(name: 'row_version')]
    public int $version;

    public int $total;
}
