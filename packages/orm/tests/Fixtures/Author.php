<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\BelongsTo;
use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\HasMany;
use Kinetis\Orm\Attributes\HasOne;

/**
 * A string identifier, a nullable relationship over the conventional
 * foreign-key column, and the inverse sides of Profile's and Post's
 * relationships to it.
 */
#[Entity(table: 'authors')]
final class Author
{
    public string $id;

    public string $name;

    #[BelongsTo]
    public ?Organization $organization;

    #[HasOne(mappedBy: 'author')]
    public ?Profile $profile;

    /** @var list<Post> */
    #[HasMany(target: Post::class, mappedBy: 'author')]
    public array $posts;
}
