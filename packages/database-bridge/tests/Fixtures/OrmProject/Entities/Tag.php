<?php

declare(strict_types=1);

namespace Kinetis\DatabaseBridge\Tests\Fixtures\OrmProject\Entities;

use Kinetis\Orm\Attributes\Entity;

#[Entity]
final class Tag
{
    public int $id;

    public string $label;
}
