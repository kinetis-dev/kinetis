<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\ListOf;

/**
 * The three element families one DTO can declare side by side: scalars
 * of two types, backed-enum cases, and nested DTOs.
 */
final readonly class TypedListsRequest
{
    public function __construct(
        #[ListOf('string')]
        public array $tags,
        #[ListOf('int')]
        public array $scores = [],
        #[ListOf(Priority::class)]
        public array $priorities = [],
        #[ListOf(OrderItem::class)]
        public array $items = [],
    ) {}
}
