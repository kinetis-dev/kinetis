<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\Entity;
use stdClass;

/** JSON properties that keep JSON objects as stdClass, over JSONB and JSON columns. */
#[Entity(table: 'kin_orm_payloads')]
final class StoredPayload
{
    public int $id;

    public stdClass $body;

    public ?stdClass $meta;

    /** @var list<mixed>|stdClass */
    public array|stdClass $document;

    /** @var list<mixed>|stdClass|null */
    public array|stdClass|null $draft;
}
