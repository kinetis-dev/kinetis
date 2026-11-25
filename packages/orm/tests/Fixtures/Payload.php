<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\Column;
use Kinetis\Orm\Attributes\Entity;
use stdClass;

/** JSON properties that keep JSON objects as stdClass, nullable or not. */
#[Entity(table: 'payloads')]
final class Payload
{
    public int $id;

    public stdClass $body;

    #[Column(name: 'meta_data')]
    public ?stdClass $meta;

    /** @var list<mixed>|stdClass */
    public array|stdClass $document;

    /** @var list<mixed>|stdClass|null */
    public null|stdClass|array $draft;
}
