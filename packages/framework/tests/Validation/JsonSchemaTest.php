<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation;

use Kinetis\Container\RequestScope;
use Kinetis\Tests\Http\Fixtures\Address;
use Kinetis\Tests\Http\Fixtures\AvatarUploadRequest;
use Kinetis\Tests\Http\Fixtures\CreateOrderRequest;
use Kinetis\Tests\Validation\Fixtures\BoundedListsRequest;
use Kinetis\Tests\Validation\Fixtures\NoConstructorFixture;
use Kinetis\Tests\Validation\Fixtures\NullableFieldsRequest;
use Kinetis\Tests\Validation\Fixtures\NullableObjectMapRequest;
use Kinetis\Tests\Validation\Fixtures\ObjectMapFieldRequest;
use Kinetis\Tests\Validation\Fixtures\OrderItem;
use Kinetis\Tests\Validation\Fixtures\OrderWithItems;
use Kinetis\Validation\Constraints\Email;
use Kinetis\Validation\Constraints\GreaterThan;
use Kinetis\Validation\Constraints\In;
use Kinetis\Validation\Constraints\LessThan;
use Kinetis\Validation\Constraints\MaxLength;
use Kinetis\Validation\Constraints\MinLength;
use Kinetis\Validation\Constraints\NotBlank;
use Kinetis\Validation\Constraints\Regex;
use Kinetis\Validation\Constraints\Url;
use Kinetis\Validation\Constraints\Uuid;
use Kinetis\Validation\Exception\JsonSchemaException;
use Kinetis\Validation\JsonSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;

final class JsonSchemaTest extends TestCase
{
    public function test_maps_builtin_scalar_types(): void
    {
        $fn = static function (int $a, float $b, bool $c, string $d) {};
        $params = (new ReflectionFunction($fn))->getParameters();

        self::assertSame(['type' => 'integer'], JsonSchema::forType($params[0]->getType()));
        self::assertSame(['type' => 'number'], JsonSchema::forType($params[1]->getType()));
        self::assertSame(['type' => 'boolean'], JsonSchema::forType($params[2]->getType()));
        self::assertSame(['type' => 'string'], JsonSchema::forType($params[3]->getType()));
    }

    // KINETIS-75: nullability is a property of the type — reflected in
    // the schema's own `type` — and never affects `required`, which is
    // driven purely by whether a default exists, matching Hydrator's/
    // McpDispatcher's actual omission behavior exactly.

    public function test_a_nullable_scalar_type_is_widened_to_include_null(): void
    {
        $fn = static function (?string $a) {};
        $params = (new ReflectionFunction($fn))->getParameters();

        self::assertSame(['type' => ['string', 'null']], JsonSchema::forType($params[0]->getType()));
    }

    public function test_a_non_nullable_scalar_type_is_unaffected(): void
    {
        $fn = static function (string $a) {};
        $params = (new ReflectionFunction($fn))->getParameters();

        self::assertSame(['type' => 'string'], JsonSchema::forType($params[0]->getType()));
    }

    public function test_a_defaultless_nullable_parameter_is_still_required(): void
    {
        $schema = JsonSchema::forClass(NullableFieldsRequest::class);

        self::assertSame(['type' => ['string', 'null']], $schema['properties']['requiredNullable']);
        self::assertContains('requiredNullable', $schema['required']);
    }

    public function test_a_nullable_parameter_with_a_default_is_not_required(): void
    {
        $schema = JsonSchema::forClass(NullableFieldsRequest::class);

        self::assertSame(['type' => ['string', 'null']], $schema['properties']['optionalNullable']);
        self::assertNotContains('optionalNullable', $schema['required']);
    }

    public function test_a_nullable_class_typed_parameter_gets_a_widened_type_when_inlined(): void
    {
        $schema = JsonSchema::forClass(NullableFieldsRequest::class);
        $itemSchema = $schema['properties']['optionalItem'];

        self::assertSame(['object', 'null'], $itemSchema['type']);
        self::assertArrayHasKey('quantity', $itemSchema['properties']);
        self::assertNotContains('optionalItem', $schema['required'], 'a defaulted parameter is never required regardless of nullability');
    }

    /**
     * A $ref can't carry a sibling `type: null` (JSON Schema combines
     * sibling keywords with $ref as an intersection, not a union — a
     * value would have to simultaneously satisfy the ref's own shape
     * AND be null, which nothing can do), so a nullable class-typed
     * parameter using the $ref-producing classSchema callback must be
     * wrapped in anyOf instead.
     */
    public function test_a_nullable_class_typed_parameter_is_wrapped_in_any_of_when_using_a_ref(): void
    {
        $schema = JsonSchema::forClass(
            NullableFieldsRequest::class,
            static fn (string $class): array => ['$ref' => "#/components/schemas/{$class}"],
        );

        self::assertSame(
            ['anyOf' => [['$ref' => '#/components/schemas/' . OrderItem::class], ['type' => 'null']]],
            $schema['properties']['optionalItem'],
        );
    }

    public function test_a_nullable_list_of_parameter_gets_a_widened_type(): void
    {
        $schema = JsonSchema::forClass(NullableFieldsRequest::class);
        $itemsSchema = $schema['properties']['optionalItems'];

        self::assertSame(['array', 'null'], $itemsSchema['type']);
        self::assertSame('object', $itemsSchema['items']['type']);
        self::assertNotContains('optionalItems', $schema['required']);
    }

    // forType() describes exactly Hydrator::SUPPORTED_BUILTIN_TYPES and
    // refuses every other builtin, rather than collapsing it into a bare
    // `object` schema no request value could satisfy.

    public function test_a_plain_array_type_is_a_real_array_schema(): void
    {
        $fn = static function (array $a, ?array $b) {};
        $params = (new ReflectionFunction($fn))->getParameters();

        self::assertSame(['type' => 'array'], JsonSchema::forType($params[0]->getType()));
        self::assertSame(['type' => ['array', 'null']], JsonSchema::forType($params[1]->getType()));
    }

    public function test_mixed_is_the_empty_schema_not_widened_for_null(): void
    {
        $fn = static function (mixed $a) {};
        $params = (new ReflectionFunction($fn))->getParameters();

        self::assertSame([], JsonSchema::forType($params[0]->getType()));
    }

    public function test_iterable_gets_the_identical_array_schema_as_plain_array(): void
    {
        $fn = static function (iterable $a, ?iterable $b) {};
        $params = (new ReflectionFunction($fn))->getParameters();

        self::assertSame(['type' => 'array'], JsonSchema::forType($params[0]->getType()));
        self::assertSame(['type' => ['array', 'null']], JsonSchema::forType($params[1]->getType()));
    }

    #[DataProvider('unsupportedBuiltinTypeProvider')]
    public function test_an_unsupported_builtin_type_throws_rather_than_being_described(callable $declaration, string $type): void
    {
        $params = (new ReflectionFunction($declaration(...)))->getParameters();

        $this->expectException(JsonSchemaException::class);
        $this->expectExceptionMessage($type);

        JsonSchema::forType($params[0]->getType());
    }

    /**
     * @return iterable<string, array{callable, string}>
     */
    public static function unsupportedBuiltinTypeProvider(): iterable
    {
        yield 'null' => [static function (null $a) {}, 'null'];
        yield 'true' => [static function (true $a) {}, 'true'];
        yield 'false' => [static function (false $a) {}, 'false'];
        yield 'object' => [static function (object $a) {}, 'object'];
        yield 'callable' => [static function (callable $a) {}, 'callable'];
    }

    public function test_a_nullable_list_of_parameter_using_a_ref_still_widens_the_arrays_own_type_not_the_items(): void
    {
        $schema = JsonSchema::forClass(
            NullableFieldsRequest::class,
            static fn (string $class): array => ['$ref' => "#/components/schemas/{$class}"],
        );
        $itemsSchema = $schema['properties']['optionalItems'];

        // The array's own type is a plain "array"/"null" pair — the
        // array itself has no $ref of its own to conflict with — while
        // each element stays exactly the bare $ref the callback
        // produced, unaffected by the array's own nullability.
        self::assertSame(['array', 'null'], $itemsSchema['type']);
        self::assertSame(['$ref' => '#/components/schemas/' . OrderItem::class], $itemsSchema['items']);
    }

    public function test_maps_each_constraint_to_its_json_schema_keyword(): void
    {
        self::assertSame(['format' => 'email'], JsonSchema::forConstraint(new Email()));
        self::assertSame(['minLength' => 5], JsonSchema::forConstraint(new MinLength(5)));
        self::assertSame(['maxLength' => 20], JsonSchema::forConstraint(new MaxLength(20)));
        self::assertSame(['exclusiveMinimum' => 0], JsonSchema::forConstraint(new GreaterThan(0)));
        self::assertSame(['exclusiveMaximum' => 120], JsonSchema::forConstraint(new LessThan(120)));
        self::assertSame(['enum' => ['admin', 'member']], JsonSchema::forConstraint(new In(['admin', 'member'])));
        self::assertSame(['format' => 'uri'], JsonSchema::forConstraint(new Url()));
        self::assertSame(['format' => 'uuid'], JsonSchema::forConstraint(new Uuid()));
    }

    public function test_not_blank_has_no_distinct_json_schema_keyword(): void
    {
        self::assertSame([], JsonSchema::forConstraint(new NotBlank()));
    }

    public function test_regex_contributes_no_json_schema_keyword(): void
    {
        self::assertSame([], JsonSchema::forConstraint(new Regex('/^[A-Z]+$/')));
    }

    public function test_a_regex_leaves_the_rest_of_a_parameters_schema_intact(): void
    {
        $fn = static function (#[Regex('/^[A-Z]+$/')] #[MinLength(3)] string $code) {};
        $params = (new ReflectionFunction($fn))->getParameters();

        self::assertSame(
            ['type' => 'string', 'minLength' => 3],
            JsonSchema::schemaForScalar($params[0], $params[0]->getType()),
        );
    }

    public function test_a_parameter_list_with_no_parameters_encodes_properties_as_a_json_object_not_an_array(): void
    {
        $schema = JsonSchema::forParameters([]);

        self::assertInstanceOf(\stdClass::class, $schema['properties']);
        self::assertSame('{"type":"object","properties":{},"required":[]}', json_encode($schema, JSON_THROW_ON_ERROR));
    }

    public function test_a_class_with_no_constructor_gets_a_bare_object_schema(): void
    {
        self::assertSame(['type' => 'object'], JsonSchema::forClass(NoConstructorFixture::class));
    }

    public function test_excluded_types_are_skipped_entirely_not_added_to_properties_or_required(): void
    {
        $fn = static function (string $name, RequestScope $scope) {};
        $params = (new ReflectionFunction($fn))->getParameters();

        $schema = JsonSchema::forParameters($params, [RequestScope::class]);

        self::assertSame(['name'], array_keys($schema['properties']));
        self::assertSame(['name'], $schema['required']);
    }

    public function test_a_nested_class_typed_parameter_is_inlined_when_no_class_schema_callback_is_given(): void
    {
        $schema = JsonSchema::forClass(CreateOrderRequest::class);

        self::assertSame('object', $schema['properties']['shippingAddress']['type']);
        self::assertArrayHasKey('street', $schema['properties']['shippingAddress']['properties']);
    }

    public function test_a_nested_class_typed_parameter_uses_the_class_schema_callback_when_given(): void
    {
        $calls = [];
        $schema = JsonSchema::forClass(CreateOrderRequest::class, function (string $class) use (&$calls) {
            $calls[] = $class;

            return ['$ref' => "#/components/schemas/{$class}"];
        });

        self::assertSame([Address::class], $calls);
        self::assertSame(['$ref' => '#/components/schemas/' . Address::class], $schema['properties']['shippingAddress']);
    }

    public function test_a_list_of_parameter_is_inlined_when_no_class_schema_callback_is_given(): void
    {
        $schema = JsonSchema::forClass(OrderWithItems::class);

        self::assertSame('array', $schema['properties']['items']['type']);
        self::assertSame('object', $schema['properties']['items']['items']['type']);
        self::assertArrayHasKey('product', $schema['properties']['items']['items']['properties']);
        self::assertArrayHasKey('quantity', $schema['properties']['items']['items']['properties']);
    }

    public function test_a_list_of_parameter_uses_the_class_schema_callback_when_given(): void
    {
        $calls = [];
        $schema = JsonSchema::forClass(OrderWithItems::class, function (string $class) use (&$calls) {
            $calls[] = $class;

            return ['$ref' => "#/components/schemas/{$class}"];
        });

        self::assertSame([OrderItem::class], $calls);
        self::assertSame(
            ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/' . OrderItem::class]],
            $schema['properties']['items'],
        );
    }

    /**
     * An #[ObjectMap] property is the one `array`-typed property whose
     * schema is an object, not an array — `additionalProperties: true`
     * being JSON Schema's own "any keys, any values", which is exactly
     * what Hydrator admits there.
     */
    public function test_an_object_map_property_is_described_as_an_open_object(): void
    {
        $schema = JsonSchema::forClass(ObjectMapFieldRequest::class);

        self::assertSame(['type' => 'object', 'additionalProperties' => true], $schema['properties']['meta']);
        self::assertSame(['name', 'meta'], $schema['required']);
    }

    public function test_a_nullable_object_map_property_is_widened_to_include_null(): void
    {
        $schema = JsonSchema::forClass(NullableObjectMapRequest::class);

        self::assertSame(
            ['type' => ['object', 'null'], 'additionalProperties' => true],
            $schema['properties']['meta'],
        );
    }

    public function test_item_bounds_map_onto_min_items_and_max_items_on_a_plain_array_property(): void
    {
        $schema = JsonSchema::forClass(BoundedListsRequest::class);

        self::assertSame(['type' => 'array', 'minItems' => 1, 'maxItems' => 3], $schema['properties']['tags']);
    }

    /**
     * The #[ListOf] branch merges ordinary constraint metadata the same
     * way the plain-array one does — a bound the request actually has to
     * satisfy is not dropped just because the property also names an
     * element class.
     */
    public function test_item_bounds_merge_into_a_list_of_property_schema(): void
    {
        $schema = JsonSchema::forClass(BoundedListsRequest::class, fn (string $class) => ['$ref' => "#/components/schemas/{$class}"]);

        self::assertSame([
            'type' => 'array',
            'items' => ['$ref' => '#/components/schemas/' . OrderItem::class],
            'minItems' => 1,
            'maxItems' => 2,
        ], $schema['properties']['items']);
    }

    /**
     * A class-typed parameter whose class cannot be instantiated has no
     * truthful object schema: Hydrator accepts only an already-constructed
     * instance there, so an expanded {type: object} would describe input it
     * rejects.
     */
    public function test_a_class_typed_parameter_that_cannot_be_instantiated_is_rejected(): void
    {
        $fn = static function (\Countable $a) {};
        $params = (new ReflectionFunction($fn))->getParameters();

        $this->expectException(JsonSchemaException::class);
        $this->expectExceptionMessage('Countable');

        JsonSchema::forParameters($params);
    }

    public function test_an_uploaded_file_field_is_still_described_as_a_binary_string(): void
    {
        $schema = JsonSchema::forClass(AvatarUploadRequest::class);

        self::assertSame(['type' => 'string', 'format' => 'binary'], $schema['properties']['avatar']);
    }
}
