<?php

declare(strict_types=1);

namespace Kinetis\DatabaseBridge\Tests\Fixtures\OrmProject\Entities;

use Kinetis\Orm\Attributes\Entity;

#[Entity(table: 'posts')]
final class Post
{
    public int $id;

    public string $title;
}
