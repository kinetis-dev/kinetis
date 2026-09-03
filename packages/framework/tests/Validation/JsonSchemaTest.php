<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation;

use Kinetis\Container\RequestScope;
use Kinetis\Tests\Http\Fixtures\Address;
use Kinetis\Tests\Http\Fixtures\CreateOrderRequest;
use Kinetis\Tests\Validation\Fixtures\NoConstructorFixture;
use Kinetis\Tests\Validation\Fixtures\NullableFieldsRequest;
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
use Kinetis\Validation\JsonSchema;
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

    // KINETIS-76: every builtin type reaching forType() must get a
    // truthful JSON Schema — never the bare `object` fallback a plain
    // array/mixed/unrepresentable type used to collapse into.

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

    // KINETIS-76 follow-up: the complete, audited policy for every one of
    // the twelve builtin type names PHP can attach to a parameter — see
    // JsonSchema::forType()'s own docblock for the full reasoning behind
    // each. `iterable` is genuinely supported (mapped to a real array
    // schema below), so `object`/`callable` — the two that remain
    // rejected — are this file's throwing examples.

    public function test_iterable_gets_the_identical_array_schema_as_plain_array(): void
    {
        $fn = static function (iterable $a, ?iterable $b) {};
        $params = (new ReflectionFunction($fn))->getParameters();

        self::assertSame(['type' => 'array'], JsonSchema::forType($params[0]->getType()));
        self::assertSame(['type' => ['array', 'null']], JsonSchema::forType($params[1]->getType()));
    }

    public function test_a_standalone_null_type_is_a_real_null_schema(): void
    {
        $fn = static function (null $a) {};
        $params = (new ReflectionFunction($fn))->getParameters();

        self::assertSame(['type' => 'null'], JsonSchema::forType($params[0]->getType()));
    }

    public function test_standalone_true_and_false_types_are_const_boolean_schemas(): void
    {
        $fn = static function (true $a, false $b) {};
        $params = (new ReflectionFunction($fn))->getParameters();

        self::assertSame(['type' => 'boolean', 'const' => true], JsonSchema::forType($params[0]->getType()));
        self::assertSame(['type' => 'boolean', 'const' => false], JsonSchema::forType($params[1]->getType()));
    }

    public function test_an_unsupported_builtin_type_throws_rather_than_being_labeled_object(): void
    {
        $fn = static function (object $a) {};
        $params = (new ReflectionFunction($fn))->getParameters();

        $this->expectException(\Kinetis\Validation\Exception\JsonSchemaException::class);
        $this->expectExceptionMessage('object');

        JsonSchema::forType($params[0]->getType());
    }

    /**
     * `callable` is rejected for a security reason, not just a
     * representational one — a JSON string handed to a callable-typed
     * parameter is exactly the shape of an arbitrary-function-name-
     * injection risk if it's ever invoked downstream, so this framework
     * never describes it as if it were safe to accept.
     */
    public function test_callable_is_rejected_as_a_security_boundary_not_just_unrepresentable(): void
    {
        $fn = static function (callable $a) {};
        $params = (new ReflectionFunction($fn))->getParameters();

        $this->expectException(\Kinetis\Validation\Exception\JsonSchemaException::class);
        $this->expectExceptionMessage('callable');

        JsonSchema::forType($params[0]->getType());
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
        self::assertSame(['pattern' => '/^[A-Z]+$/'], JsonSchema::forConstraint(new Regex('/^[A-Z]+$/')));
        self::assertSame(['enum' => ['admin', 'member']], JsonSchema::forConstraint(new In(['admin', 'member'])));
        self::assertSame(['format' => 'uri'], JsonSchema::forConstraint(new Url()));
        self::assertSame(['format' => 'uuid'], JsonSchema::forConstraint(new Uuid()));
    }

    public function test_not_blank_has_no_distinct_json_schema_keyword(): void
    {
        self::assertSame([], JsonSchema::forConstraint(new NotBlank()));
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
}
