<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\Absent;

/**
 * Every field is optional, and each is optional in a different way — a
 * plain default, a nullable default, and a presence union — so the
 * context's answer cannot come from the constructed values, which look
 * identical whether the client sent them or not.
 */
#[RecordsSuppliedFields('first', 'second', 'third')]
final readonly class SuppliedFieldsRequest
{
    public function __construct(
        public string $first = 'default',
        public ?string $second = null,
        public string|null|Absent $third = Absent::Value,
    ) {}
}
