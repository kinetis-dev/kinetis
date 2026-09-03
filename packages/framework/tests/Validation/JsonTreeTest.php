<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation;

use Kinetis\Validation\JsonObject;
use Kinetis\Validation\JsonTree;
use PHPUnit\Framework\TestCase;

final class JsonTreeTest extends TestCase
{
    public function test_a_json_array_stays_a_plain_array(): void
    {
        $decoded = json_decode('["a", "b"]', associative: false);

        self::assertSame(['a', 'b'], JsonTree::convert($decoded));
    }

    public function test_a_json_object_becomes_a_json_object_marker(): void
    {
        $decoded = json_decode('{"key": "value"}', associative: false);

        $converted = JsonTree::convert($decoded);

        self::assertInstanceOf(JsonObject::class, $converted);
        self::assertSame(['key' => 'value'], $converted->toArray());
    }

    /**
     * The exact gap array_is_list() alone cannot close: a JSON object
     * whose own keys happen to look like a sequential list decodes to
     * the identical PHP shape a real JSON array does once flattened via
     * associative: true — array_is_list() returns true for both. Only
     * decoding with associative: false first, before that distinction is
     * erased, can tell them apart.
     */
    public function test_a_json_object_with_sequential_numeric_keys_still_becomes_a_marker(): void
    {
        $decoded = json_decode('{"0": "a", "1": "b"}', associative: false);

        // Confirms the real PHP behavior this class exists to work
        // around, not just asserts on JsonTree's own output.
        $flattened = json_decode('{"0": "a", "1": "b"}', associative: true);
        self::assertTrue(array_is_list($flattened), 'array_is_list() alone cannot distinguish this case');

        $converted = JsonTree::convert($decoded);

        self::assertInstanceOf(JsonObject::class, $converted);
        self::assertSame(['a', 'b'], $converted->toArray());
    }

    public function test_an_empty_json_object_becomes_a_marker_wrapping_an_empty_array(): void
    {
        $decoded = json_decode('{}', associative: false);

        $converted = JsonTree::convert($decoded);

        self::assertInstanceOf(JsonObject::class, $converted);
        self::assertSame([], $converted->toArray());
    }

    public function test_an_empty_json_array_stays_a_plain_empty_array(): void
    {
        $decoded = json_decode('[]', associative: false);

        self::assertSame([], JsonTree::convert($decoded));
    }

    public function test_conversion_recurses_into_nested_arrays_and_objects(): void
    {
        $decoded = json_decode('{"items": [{"id": 1}, {"id": 2}]}', associative: false);

        $converted = JsonTree::convert($decoded);
        self::assertInstanceOf(JsonObject::class, $converted);

        $items = $converted->toArray()['items'];
        self::assertIsArray($items);
        self::assertCount(2, $items);
        self::assertInstanceOf(JsonObject::class, $items[0]);
        self::assertSame(['id' => 1], $items[0]->toArray());
        self::assertInstanceOf(JsonObject::class, $items[1]);
        self::assertSame(['id' => 2], $items[1]->toArray());
    }

    public function test_scalars_and_null_pass_through_unchanged(): void
    {
        self::assertSame('hello', JsonTree::convert('hello'));
        self::assertSame(42, JsonTree::convert(42));
        self::assertTrue(JsonTree::convert(true));
        self::assertNull(JsonTree::convert(null));
    }

    public function test_unwrap_is_the_exact_inverse_of_convert_for_a_deeply_nested_tree(): void
    {
        $raw = '{"a": {"b": [{"c": "d"}], "e": {"0": "x", "1": "y"}}}';
        $decoded = json_decode($raw, associative: false);
        $associative = json_decode($raw, associative: true);

        $converted = JsonTree::convert($decoded);

        self::assertSame($associative, JsonTree::unwrap($converted));
    }

    public function test_unwrap_is_a_no_op_for_a_tree_that_was_never_converted(): void
    {
        $plain = ['a' => ['b' => 1, 'c' => [1, 2, 3]]];

        self::assertSame($plain, JsonTree::unwrap($plain));
    }
}
