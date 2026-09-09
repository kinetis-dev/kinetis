<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Constraints;

use InvalidArgumentException;
use Kinetis\Validation\Constraints\MaxItems;
use Kinetis\Validation\Constraints\MinItems;
use PHPUnit\Framework\TestCase;

/**
 * #[MinItems]/#[MaxItems]' own contract, invoked directly — the
 * boundaries, the constructor bound, and what a value Hydrator would
 * never have let through gets back. Their behavior on a real DTO is in
 * HydratorTest, and their JSON Schema keywords in JsonSchemaTest.
 */
final class ItemCountTest extends TestCase
{
    public function test_min_items_accepts_a_list_at_or_above_the_bound(): void
    {
        self::assertNull(new MinItems(2)->validate(['a', 'b']));
        self::assertNull(new MinItems(2)->validate(['a', 'b', 'c']));
    }

    public function test_min_items_rejects_a_list_below_the_bound(): void
    {
        self::assertSame('must contain at least 2 items.', new MinItems(2)->validate(['a']));
    }

    public function test_max_items_accepts_a_list_at_or_below_the_bound(): void
    {
        self::assertNull(new MaxItems(2)->validate(['a', 'b']));
        self::assertNull(new MaxItems(2)->validate(['a']));
    }

    public function test_max_items_rejects_a_list_above_the_bound(): void
    {
        self::assertSame('must contain at most 2 items.', new MaxItems(2)->validate(['a', 'b', 'c']));
    }

    /**
     * Zero is a real bound, not a disabled one: #[MaxItems(0)] admits
     * only the empty list, and #[MinItems(0)] admits every list.
     */
    public function test_a_zero_bound_is_enforced_as_written(): void
    {
        self::assertNull(new MaxItems(0)->validate([]));
        self::assertSame('must contain at most 0 items.', new MaxItems(0)->validate(['a']));
        self::assertNull(new MinItems(0)->validate([]));
    }

    /**
     * A negative bound describes no list at all, so it fails where the
     * constraint is first instantiated — during hydration or schema
     * generation — rather than silently admitting everything.
     */
    public function test_a_negative_min_items_bound_is_refused_at_construction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('MinItems count must not be negative, got -1.');

        new MinItems(-1);
    }

    public function test_a_negative_max_items_bound_is_refused_at_construction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('MaxItems count must not be negative, got -1.');

        new MaxItems(-1);
    }

    /**
     * Type mismatch stays Hydrator's responsibility — a non-list never
     * reaches these constraints through a real request. Invoked
     * directly they still answer with a list-shape message rather than
     * counting something that has no count. The message names the JSON
     * array the field claims, so it stays true of a map-shaped PHP array
     * as much as of a scalar or a null.
     */
    public function test_a_non_list_value_gets_a_list_shape_message(): void
    {
        self::assertSame('must be a JSON array.', new MinItems(1)->validate('nope'));
        self::assertSame('must be a JSON array.', new MaxItems(1)->validate('nope'));
        self::assertSame('must be a JSON array.', new MinItems(1)->validate(['key' => 'value']));
        self::assertSame('must be a JSON array.', new MaxItems(1)->validate(['key' => 'value']));
        self::assertSame('must be a JSON array.', new MinItems(0)->validate(null));
    }
}
