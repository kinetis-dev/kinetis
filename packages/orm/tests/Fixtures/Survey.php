<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\Column;
use Kinetis\Orm\Attributes\Entity;

/** JSON properties, nullable or not, one over a renamed column. */
#[Entity(table: 'surveys')]
final class Survey
{
    public int $id;

    /** @var array<array-key, mixed> */
    public array $answers;

    /** @var array<array-key, mixed>|null */
    #[Column(name: 'extra_data')]
    public ?array $extra;
}
