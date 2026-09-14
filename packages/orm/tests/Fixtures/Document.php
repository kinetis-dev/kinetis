<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\Entity;

/** A string identifier holding application-generated UUID text. */
#[Entity(table: 'documents')]
final class Document
{
    public string $id;

    public string $title;
}
