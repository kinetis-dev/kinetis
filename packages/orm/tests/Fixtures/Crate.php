<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\HasMany;
use Kinetis\Orm\Attributes\HasOne;
use Kinetis\Orm\Attributes\Id;

/** An aggregate root with a generated identifier, an owned collection and an owned unique target. */
#[Entity(table: 'crates')]
final class Crate
{
    #[Id(generated: true)]
    public ?int $id = null;

    public string $code;

    /** @var list<Item> */
    #[HasMany(target: Item::class, mappedBy: 'crate', owned: true)]
    public array $items;

    #[HasOne(mappedBy: 'crate', owned: true)]
    public ?Seal $seal;

    public static function of(string $code): self
    {
        $crate = new self();
        $crate->code = $code;

        return $crate;
    }
}
