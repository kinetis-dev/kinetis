<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Linting\Fixtures;

/**
 * `MappedEntity` without the attributes. Nothing maps these properties,
 * so PHPStan's reports about them are correct and the extension must
 * leave every one of them standing.
 */
final class UnmappedMirror
{
    private ?int $id = null;

    private int $version = 1;

    private string $body;

    private MappedOwner $ticket;

    public function __construct(string $body, MappedOwner $ticket, private string $author)
    {
        $this->body = $body;
        $this->ticket = $ticket;
    }
}
