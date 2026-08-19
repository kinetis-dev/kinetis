<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation;

use Kinetis\Container\RequestScope;
use Kinetis\Tests\Http\Fixtures\Address;
use Kinetis\Tests\Http\Fixtures\CreateOrderRequest;
use Kinetis\Tests\Validation\Fixtures\NoConstructorFixture;
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
