<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Linting\Fixtures;

use Kinetis\Orm\Attributes\BelongsTo;
use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\Id;
use Kinetis\Orm\Attributes\Version;

/**
 * Every mapped shape the ORM reads through reflection and nothing else
 * reads: a generated identifier, a version, a plain column, a promoted
 * plain column and a relationship owner.
 */
#[Entity(table: 'replies')]
final class MappedEntity
{
    #[Id(generated: true)]
    private ?int $id = null;

    #[Version]
    private int $version = 1;

    private string $body;

    #[BelongsTo]
    private MappedOwner $ticket;

    public function __construct(string $body, MappedOwner $ticket, private string $author)
    {
        $this->body = $body;
        $this->ticket = $ticket;
    }
}
