<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\HasMany;

/** An entity on the "ledger" connection, owning its entries there. */
#[Entity(table: 'ledger_accounts', connection: 'ledger')]
final class LedgerAccount
{
    public int $id;

    public string $name;

    /** @var list<LedgerEntry> */
    #[HasMany(target: LedgerEntry::class, mappedBy: 'account')]
    public array $entries;
}
