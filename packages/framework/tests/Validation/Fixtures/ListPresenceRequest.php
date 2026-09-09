<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\Absent;
use Kinetis\Validation\ListOf;

/**
 * A presence union around each element family, so an update DTO can
 * tell a list the client omitted from one it sent empty — and, for the
 * nullable one, from one it explicitly cleared.
 */
final readonly class ListPresenceRequest
{
    public function __construct(
        #[ListOf('string')]
        public array|Absent $tags = Absent::Value,
        #[ListOf(Priority::class)]
        public array|null|Absent $priorities = Absent::Value,
        #[ListOf(OrderItem::class)]
        public array|Absent $items = Absent::Value,
    ) {}
}
