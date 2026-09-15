<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\BelongsTo;
use Kinetis\Orm\Attributes\Entity;

/** The target of Author's nullable #[HasOne]. */
#[Entity(table: 'profiles')]
final class Profile
{
    public int $id;

    #[BelongsTo]
    public Author $author;

    public string $bio;
}
