<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\ManyToMany;

/** Both ends of one self-referential join table, over its two distinct columns. */
#[Entity(table: 'peers')]
final class Peer
{
    public int $id;

    public string $name;

    /** @var list<self> */
    #[ManyToMany(target: self::class, table: 'peer_link', joinColumn: 'peer_id', inverseJoinColumn: 'linked_id')]
    public array $links;

    /** @var list<self> */
    #[ManyToMany(target: self::class, mappedBy: 'links')]
    public array $linkedBy;

    public static function named(int $id, string $name): self
    {
        $peer = new self();
        $peer->id = $id;
        $peer->name = $name;

        return $peer;
    }
}
