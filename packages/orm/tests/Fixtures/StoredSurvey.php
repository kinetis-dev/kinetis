<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\Column;
use Kinetis\Orm\Attributes\Entity;

/** JSON properties over a JSONB or JSON column, nullable or not. */
#[Entity(table: 'kin_orm_surveys')]
final class StoredSurvey
{
    public int $id;

    /** @var array<array-key, mixed> */
    public array $answers;

    /** @var array<array-key, mixed>|null */
    #[Column(name: 'extra_data')]
    public ?array $extra;
}
