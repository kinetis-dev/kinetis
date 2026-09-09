<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation;

use Kinetis\Container\RequestScope;
use Kinetis\Tests\Http\Fixtures\Address;
use Kinetis\Tests\Http\Fixtures\AvatarUploadRequest;
use Kinetis\Tests\Http\Fixtures\CreateOrderRequest;
use Kinetis\Tests\Validation\Fixtures\ApplicationRulesRequest;
use Kinetis\Tests\Validation\Fixtures\BoundedListsRequest;
use Kinetis\Tests\Validation\Fixtures\ClaimsKeyword;
use Kinetis\Tests\Validation\Fixtures\DuplicateKeywordRequest;
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
use Kinetis\Validation\Constraints\MaxItems;
use Kinetis\Validation\Constraints\MaxLength;
use Kinetis\Validation\Constraints\MinItems;
use Kinetis\Validation\Constraints\MinLength;
use Kinetis\Validation\Constraints\NotBlank;
use Kinetis\Validation\Constraints\Regex;
use Kinetis\Validation\Constraints\Url;
use Kinetis\Validation\Constraints\Uuid;
use Kinetis\Validation\Exception\JsonSchemaException;
use Kinetis\Validation\JsonSchema;
use Kinetis\Validation\ListOf;
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

    /**
     * Each rule's keywords reach a parameter's schema through the same
     * #[Attribute] -> {class, args} descriptor reading Hydrator
     * validates through, so this is the published document, not a
     * separate mapping table that could drift from it. Each rule's own
     * schema() answer is asserted in ConstraintCatalogueTest.
     */
    public function test_each_constraint_merges_its_own_keywords_into_the_parameters_schema(): void
    {
        $fn = static function (
            #[Email] string $email,
            #[MinLength(5)] string $atLeast,
            #[MaxLength(20)] string $atMost,
            #[GreaterThan(0)] int $above,
            #[LessThan(120)] int $below,
            #[In(['admin', 'member'])] string $role,
            #[Url] string $link,
            #[Uuid] string $id,
            #[MinItems(1)] array $some,
            #[MaxItems(3)] array $few,
        ) {};
        $params = (new ReflectionFunction($fn))->getParameters();

        $expected = [
            ['type' => 'string', 'format' => 'email'],
            ['type' => 'string', 'minLength' => 5],
            ['type' => 'string', 'maxLength' => 20],
            ['type' => 'integer', 'exclusiveMinimum' => 0],
            ['type' => 'integer', 'exclusiveMaximum' => 120],
            ['type' => 'string', 'enum' => ['admin', 'member']],
            ['type' => 'string', 'format' => 'uri'],
            ['type' => 'string', 'format' => 'uuid'],
            ['type' => 'array', 'minItems' => 1],
            ['type' => 'array', 'maxItems' => 3],
        ];

        foreach ($expected as $index => $schema) {
            self::assertSame($schema, JsonSchema::schemaForScalar($params[$index], $params[$index]->getType()));
        }
    }

    public function test_a_runtime_only_constraint_leaves_the_schema_untouched(): void
    {
        $fn = static function (#[NotBlank] string $name, #[Regex('/^[A-Z]+$/')] string $code) {};
        $params = (new ReflectionFunction($fn))->getParameters();

        self::assertSame(['type' => 'string'], JsonSchema::schemaForScalar($params[0], $params[0]->getType()));
        self::assertSame(['type' => 'string'], JsonSchema::schemaForScalar($params[1], $params[1]->getType()));
    }

    /**
     * An application constraint describes itself in the published
     * document with nothing registered anywhere — the same reading that
     * finds #[Email] finds it, and the keywords come from the rule.
     */
    public function test_an_application_constraint_publishes_its_own_keywords(): void
    {
        $schema = JsonSchema::forClass(ApplicationRulesRequest::class);

        // #[Uppercase]'s `pattern` merged in; #[NotReserved], which has
        // no keyword to state, added nothing.
        self::assertSame(['type' => 'string', 'pattern' => '^[A-Z]+$'], $schema['properties']['code']);
    }

    /**
     * Declaration order must never silently decide which of two bounds
     * a client is told about: one of them would then be a rule the
     * request is checked against but the document never mentions.
     */
    public function test_two_rules_claiming_the_same_keyword_are_refused(): void
    {
        $this->expectException(JsonSchemaException::class);
        $this->expectExceptionMessage('both contribute the JSON Schema keyword "minLength"');

        JsonSchema::forClass(DuplicateKeywordRequest::class);
    }

    /**
     * The PHP declaration owns the shape and a rule refines it. A rule
     * contributing `type` back would publish a shape Hydrator does not
     * check against — its type check reads the declared PHP type and
     * nothing else — so the declaration is refused rather than merged,
     * the same answer two rules claiming one keyword get.
     */
    public function test_a_rule_cannot_restate_the_type_the_php_declaration_owns(): void
    {
        $fn = static function (#[ClaimsKeyword('type', 'string')] int $count) {};
        $params = (new ReflectionFunction($fn))->getParameters();

        $this->expectException(JsonSchemaException::class);
        $this->expectExceptionMessage('contributes the JSON Schema keyword "type", which the parameter\'s own PHP type already states');

        JsonSchema::schemaForScalar($params[0], $params[0]->getType());
    }

    /**
     * The same ownership one level in: a #[ListOf] list's `items` comes
     * from the item class the attribute names, so a rule may bound the
     * list (#[MinItems] contributes `minItems`) but never redescribe
     * what is in it.
     */
    public function test_a_rule_cannot_restate_the_items_a_list_of_declaration_owns(): void
    {
        $fn = static function (
            #[ClaimsKeyword('items', ['type' => 'string'])]
            #[ListOf(OrderItem::class)]
            array $items,
        ) {};

        $this->expectException(JsonSchemaException::class);
        $this->expectExceptionMessage('contributes the JSON Schema keyword "items"');

        JsonSchema::forParameters((new ReflectionFunction($fn))->getParameters());
    }

    /**
     * The rule this refusal is scoped to: a keyword no declaration
     * states is the rule's own, and whatever it nests inside that
     * keyword is never inspected — an `enum` full of values, a
     * `format`'s name, a rule's own nested `type` under its own keyword.
     */
    public function test_a_rule_owning_its_own_keyword_still_merges(): void
    {
        $fn = static function (#[ClaimsKeyword('contentSchema', ['type' => 'string'])] string $note) {};
        $params = (new ReflectionFunction($fn))->getParameters();

        self::assertSame(
            ['type' => 'string', 'contentSchema' => ['type' => 'string']],
            JsonSchema::schemaForScalar($params[0], $params[0]->getType()),
        );
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
