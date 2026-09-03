<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\ListOf;

/**
 * Every nullable-field shape KINETIS-75 covers, in one fixture:
 * requiredNullable has no default (still required — omitting it is
 * rejected, only an explicitly-null value is accepted); optionalNullable
 * has a default (genuinely optional); optionalItem is a nullable
 * class-typed nested DTO; optionalItems is a nullable #[ListOf] array.
 */
final readonly class NullableFieldsRequest
{
    public function __construct(
        public ?string $requiredNullable,
        public ?string $optionalNullable = null,
        public ?OrderItem $optionalItem = null,
        #[ListOf(OrderItem::class)]
        public ?array $optionalItems = null,
    ) {}
}
