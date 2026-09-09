<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation;

use Kinetis\Tests\Validation\Fixtures\BackedEnumInterfaceFieldRequest;
use Kinetis\Tests\Validation\Fixtures\EmptyBackedEnumFieldRequest;
use Kinetis\Tests\Validation\Fixtures\EnumRuleClaimsEnumRequest;
use Kinetis\Tests\Validation\Fixtures\EnumFieldsRequest;
use Kinetis\Tests\Validation\Fixtures\Priority;
use Kinetis\Tests\Validation\Fixtures\SortDirection;
use Kinetis\Tests\Validation\Fixtures\UnitEnumFieldRequest;
use Kinetis\Tests\Validation\Fixtures\Weekday;
use Kinetis\Validation\Exception\JsonSchemaException;
use Kinetis\Validation\Exception\UnsupportedDtoDefinitionException;
use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\Hydrator;
use Kinetis\Validation\InputSource;
use Kinetis\Validation\JsonObject;
use Kinetis\Validation\JsonSchema;
use Kinetis\Validation\JsonTree;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A backed enum as a DTO field: its wire value is the scalar its cases
 * are written in, resolved as that scalar first and turned into the
 * case it names.
 */
final class BackedEnumTest extends TestCase
{
    public function test_a_string_backed_field_binds_the_case_its_value_names(): void
    {
        $dto = Hydrator::hydrate(
            EnumFieldsRequest::class,
            self::decodedBody('{"priority": 2, "direction": "desc"}'),
            null,
            InputSource::Json,
        );

        self::assertSame(Priority::Normal, $dto->priority);
        self::assertSame(SortDirection::Descending, $dto->direction);
    }

    public function test_an_unknown_backing_value_reports_enum_case_and_the_exact_choices(): void
    {
        try {
            Hydrator::hydrate(
                EnumFieldsRequest::class,
                self::decodedBody('{"priority": 2, "direction": "sideways"}'),
                null,
                InputSource::Json,
            );
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['direction'], $e->violations[0]->path);
            self::assertSame('enum_case', $e->violations[0]->code);
            self::assertSame('must be one of: asc, desc.', $e->violations[0]->message);
            self::assertSame(['choices' => ['asc', 'desc']], $e->violations[0]->parameters);
        }
    }

    /**
     * The two failures stay distinct: a value of the wrong primitive
     * never named a case at all, and saying so is what tells a client
     * to change the type rather than the value.
     */
    public function test_a_wrong_primitive_is_a_type_violation(): void
    {
        try {
            Hydrator::hydrate(
                EnumFieldsRequest::class,
                self::decodedBody('{"priority": 2, "direction": 5}'),
                null,
                InputSource::Json,
            );
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame('type_mismatch', $e->violations[0]->code);
            self::assertSame(['expected' => 'string', 'given' => 'integer'], $e->violations[0]->parameters);
        }
    }

    public function test_an_existing_case_is_taken_as_given(): void
    {
        $dto = Hydrator::hydrate(EnumFieldsRequest::class, [
            'priority' => Priority::High,
            'direction' => SortDirection::Ascending,
        ]);

        self::assertSame(Priority::High, $dto->priority);
        self::assertSame(SortDirection::Ascending, $dto->direction);
    }

    public function test_a_nullable_enum_field_accepts_an_explicit_null(): void
    {
        $dto = Hydrator::hydrate(
            EnumFieldsRequest::class,
            self::decodedBody('{"priority": 1, "direction": null}'),
            null,
            InputSource::Json,
        );

        self::assertNull($dto->direction);
    }

    /**
     * `2.0` is how a JSON producer with one number type writes the
     * integer 2, and `"2"` is how text writes it — each source's own
     * spelling of an int reaches tryFrom() as a real int, so a correctly
     * typed value naming no case is the only enum failure there is.
     *
     * @param array<string, mixed> $data
     */
    #[DataProvider('integerBackedSpellings')]
    public function test_an_int_backed_field_binds_each_sources_own_spelling(
        array $data,
        InputSource $source,
        ?Priority $expected,
        ?string $code,
    ): void {
        try {
            $dto = Hydrator::hydrate(EnumFieldsRequest::class, $data, null, $source);

            self::assertSame($expected, $dto->priority);
        } catch (ValidationException $e) {
            self::assertSame($code, $e->violations[0]->code);
            self::assertSame(['priority'], $e->violations[0]->path);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, InputSource, ?Priority, ?string}>
     */
    public static function integerBackedSpellings(): iterable
    {
        yield 'a JSON integer' => [['priority' => 2], InputSource::Json, Priority::Normal, null];
        yield 'an integer-valued JSON float' => [['priority' => 2.0], InputSource::Json, Priority::Normal, null];
        yield 'the text spelling of an integer' => [['priority' => '2'], InputSource::Text, Priority::Normal, null];
        yield 'a JSON string' => [['priority' => '2'], InputSource::Json, null, 'type_mismatch'];
        yield 'a fractional JSON number' => [['priority' => 2.5], InputSource::Json, null, 'not_an_integer'];
        yield 'an integer naming no case' => [['priority' => 9], InputSource::Json, null, 'enum_case'];
    }

    public function test_a_field_rule_reads_the_resolved_case(): void
    {
        try {
            Hydrator::hydrate(
                EnumFieldsRequest::class,
                self::decodedBody('{"priority": 3, "escalation": 1}'),
                null,
                InputSource::Json,
            );
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['escalation'], $e->violations[0]->path);
            self::assertSame('minimum_priority', $e->violations[0]->code);
        }
    }

    public function test_the_compiled_plan_records_the_enum_and_the_type_it_is_backed_by(): void
    {
        $plan = Hydrator::compilePlan(EnumFieldsRequest::class);

        self::assertSame('int', $plan['parameters'][0]['scalarType']);
        self::assertSame(Priority::class, $plan['parameters'][0]['enumClass']);
        self::assertNull($plan['parameters'][0]['dtoClass']);
        self::assertNull($plan['parameters'][0]['nestedPlan']);

        self::assertSame('string', $plan['parameters'][1]['scalarType']);
        self::assertSame(SortDirection::class, $plan['parameters'][1]['enumClass']);
    }

    /**
     * A unit enum has no backing values, so nothing changes for it: the
     * field keeps the instance-only shape every non-instantiable class
     * has.
     */
    public function test_a_unit_enum_field_still_accepts_only_an_existing_case(): void
    {
        $plan = Hydrator::compilePlan(UnitEnumFieldRequest::class);

        self::assertNull($plan['parameters'][0]['enumClass']);
        self::assertSame(Weekday::class, $plan['parameters'][0]['dtoClass']);
        self::assertNull($plan['parameters'][0]['nestedPlan']);

        self::assertSame(Weekday::Monday, Hydrator::hydrate(UnitEnumFieldRequest::class, ['day' => Weekday::Monday])->day);

        try {
            Hydrator::hydrate(UnitEnumFieldRequest::class, ['day' => 'Monday']);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame('not_an_instance', $e->violations[0]->code);
        }
    }

    public function test_an_empty_backed_enum_field_is_a_definition_error(): void
    {
        $this->expectException(UnsupportedDtoDefinitionException::class);
        $this->expectExceptionMessage('is a backed enum with no cases');

        Hydrator::compilePlan(EmptyBackedEnumFieldRequest::class);
    }

    public function test_an_empty_backed_enum_is_refused_by_schema_generation_too(): void
    {
        $this->expectException(UnsupportedDtoDefinitionException::class);
        $this->expectExceptionMessage('is a backed enum with no cases');

        JsonSchema::forClass(EmptyBackedEnumFieldRequest::class);
    }

    public function test_an_enum_field_publishes_its_backing_type_and_exact_cases(): void
    {
        $schema = JsonSchema::forClass(EnumFieldsRequest::class);

        self::assertSame(['type' => 'integer', 'enum' => [1, 2, 3]], $schema['properties']['priority']);
    }

    /**
     * Both domains the value has to satisfy are widened, so a client
     * generating requests from the document can send the null the
     * declaration accepts.
     */
    public function test_a_nullable_enum_field_publishes_null_in_both_domains(): void
    {
        $schema = JsonSchema::forClass(EnumFieldsRequest::class);

        self::assertSame(
            ['type' => ['string', 'null'], 'enum' => ['asc', 'desc', null]],
            $schema['properties']['direction'],
        );
    }

    /**
     * The runtime applies a field's rules to the resolved case, so the
     * document has to state them: a rule whose keywords went unmerged
     * would publish a domain wider than the one a request is held to.
     * The keyword describes the wire value, which for an enum is its
     * backing scalar.
     */
    public function test_a_field_rules_keywords_merge_into_the_enum_schema(): void
    {
        $schema = JsonSchema::forClass(EnumFieldsRequest::class);

        self::assertSame(
            ['type' => ['integer', 'null'], 'enum' => [1, 2, 3, null], 'minimum' => 2],
            $schema['properties']['escalation'],
        );
    }

    /**
     * And the collision contract holds there too: the enum owns `type`
     * and `enum`, so a rule restating either would publish a domain
     * Hydrator never checks the request against.
     */
    public function test_a_rule_cannot_restate_an_enum_fields_own_cases(): void
    {
        $this->expectException(JsonSchemaException::class);
        $this->expectExceptionMessage('contributes the JSON Schema keyword "enum"');

        JsonSchema::forClass(EnumRuleClaimsEnumRequest::class);
    }

    // --- `BackedEnum` itself is an interface, not an enum backed by
    // anything: it satisfies is_a() against itself and its inherited
    // cases() is abstract, so asking it for cases is an engine Error.
    // A field naming it keeps the instance-only shape every other
    // interface-typed field has. ---

    public function test_the_backed_enum_interface_is_an_instance_only_field(): void
    {
        $plan = Hydrator::compilePlan(BackedEnumInterfaceFieldRequest::class);

        self::assertNull($plan['parameters'][0]['enumClass']);
        self::assertNull($plan['parameters'][0]['scalarType']);
        self::assertSame(\BackedEnum::class, $plan['parameters'][0]['dtoClass']);
        self::assertNull($plan['parameters'][0]['nestedPlan']);

        $dto = Hydrator::hydrate(BackedEnumInterfaceFieldRequest::class, ['choice' => Priority::High]);
        self::assertSame(Priority::High, $dto->choice);
    }

    public function test_a_wire_value_cannot_fill_a_backed_enum_interface_field(): void
    {
        try {
            Hydrator::hydrate(
                BackedEnumInterfaceFieldRequest::class,
                self::decodedBody('{"choice": 2}'),
                null,
                InputSource::Json,
            );
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame('not_an_instance', $e->violations[0]->code);
            self::assertSame(['class' => \BackedEnum::class], $e->violations[0]->parameters);
        }
    }

    public function test_the_backed_enum_interface_field_has_no_publishable_schema(): void
    {
        $this->expectException(JsonSchemaException::class);
        $this->expectExceptionMessage('Cannot generate a JSON Schema for "BackedEnum"');

        JsonSchema::forClass(BackedEnumInterfaceFieldRequest::class);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodedBody(string $json): array
    {
        $converted = JsonTree::convert(json_decode($json, associative: false));
        self::assertInstanceOf(JsonObject::class, $converted);

        return $converted->toArray();
    }
}
