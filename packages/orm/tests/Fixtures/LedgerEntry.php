<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\BelongsTo;
use Kinetis\Orm\Attributes\Entity;

/** A relationship between two entities of the "ledger" connection. */
#[Entity(table: 'ledger_entries', connection: 'ledger')]
final class LedgerEntry
{
    public int $id;

    public int $amount;

    #[BelongsTo]
    public LedgerAccount $account;
}
