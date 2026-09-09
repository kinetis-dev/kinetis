<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation;

use Kinetis\Tests\Validation\Fixtures\AbsentEnumSupertypeRequest;
use Kinetis\Tests\Validation\Fixtures\AbsentUnionShapesRequest;
use Kinetis\Tests\Validation\Fixtures\AbsentWithoutDefaultRequest;
use Kinetis\Tests\Validation\Fixtures\AbsentWithoutValueTypeRequest;
use Kinetis\Tests\Validation\Fixtures\AbsentWithTwoValueTypesRequest;
use Kinetis\Tests\Validation\Fixtures\AbsentWithWrongDefaultRequest;
use Kinetis\Tests\Validation\Fixtures\CreateArticleRequest;
use Kinetis\Tests\Validation\Fixtures\OrderItem;
use Kinetis\Tests\Validation\Fixtures\SortDirection;
use Kinetis\Tests\Validation\Fixtures\UpdateArticleRequest;
use Kinetis\Validation\Absent;
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
 * The `T|Absent` presence union: the three states an update DTO field
 * distinguishes, the declarations that are refused, and what a schema
 * says about a field carrying one.
 */
final class AbsentTest extends TestCase
{
    /**
     * The contrast the pair exists for. A create DTO's defaultless field
     * rejects omission outright; the update DTO's presence union accepts
     * it and records that it happened.
     */
    public function test_a_create_dto_rejects_an_omitted_field_the_update_dto_accepts(): void
    {
        try {
            Hydrator::hydrate(CreateArticleRequest::class, ['summary' => null], source: InputSource::Json);
            self::fail('Expected the create DTO to reject an omitted title.');
        } catch (ValidationException $e) {
            self::assertSame(['title'], $e->violations[0]->path);
            self::assertSame('required', $e->violations[0]->code);
        }

        $updated = Hydrator::hydrate(UpdateArticleRequest::class, ['summary' => 'x'], source: InputSource::Json);

        self::assertSame(Absent::Value, $updated->title);
    }

    /**
     * Omitted, sent as a value, and sent as `null` are three answers, and
     * the field holds a different one for each. `title` declares no
     * `null`, so the third answer is a violation there rather than a
     * value — presence and nullability stay independent.
     */
    public function test_an_update_field_distinguishes_omitted_a_value_and_an_explicit_null(): void
    {
        $omitted = Hydrator::hydrate(UpdateArticleRequest::class, ['title' => 'kept'], source: InputSource::Json);
        self::assertSame(Absent::Value, $omitted->summary);

        $supplied = Hydrator::hydrate(
            UpdateArticleRequest::class,
            ['summary' => 'a summary'],
            source: InputSource::Json,
        );
        self::assertSame('a summary', $supplied->summary);

        $cleared = Hydrator::hydrate(UpdateArticleRequest::class, ['summary' => null], source: InputSource::Json);
        self::assertNull($cleared->summary);

        $this->expectException(ValidationException::class);

        Hydrator::hydrate(UpdateArticleRequest::class, ['title' => null], source: InputSource::Json);
    }

    public function test_a_non_nullable_presence_field_reports_an_explicit_null_as_a_violation(): void
    {
        try {
            Hydrator::hydrate(UpdateArticleRequest::class, ['title' => null], source: InputSource::Json);
            self::fail('Expected an explicit null on a T|Absent field to be refused.');
        } catch (ValidationException $e) {
            self::assertSame(['title'], $e->violations[0]->path);
            self::assertSame('null_not_allowed', $e->violations[0]->code);
        }
    }

    /**
     * A supplied value is checked exactly as `T` alone would be — the
     * union widens presence, never the admitted value domain.
     */
    public function test_a_supplied_value_is_resolved_strictly_as_the_value_type(): void
    {
        try {
            Hydrator::hydrate(UpdateArticleRequest::class, ['title' => 42], source: InputSource::Json);
            self::fail('Expected a JSON number to be refused for a string field.');
        } catch (ValidationException $e) {
            self::assertSame('type_mismatch', $e->violations[0]->code);
            self::assertSame(['expected' => 'string', 'given' => 'integer'], $e->violations[0]->parameters);
        }
    }

    /**
     * Rules describe a value the client sent. An omitted member binds an
     * application-owned default instead, which no wire rule has anything
     * to say about — #[NotBlank] would reject the marker outright if it
     * ran.
     */
    public function test_an_omitted_presence_field_skips_its_own_constraints(): void
    {
        $article = Hydrator::hydrate(UpdateArticleRequest::class, ['summary' => 's'], source: InputSource::Json);

        self::assertSame(Absent::Value, $article->title);

        $this->expectException(ValidationException::class);

        Hydrator::hydrate(UpdateArticleRequest::class, ['title' => '  '], source: InputSource::Json);
    }

    public function test_a_presence_union_wraps_every_value_shape_a_field_can_declare(): void
    {
        $json = '{"quantity": 2, "item": {"product": "Widget", "quantity": 1},'
            . ' "items": [{"product": "Bolt", "quantity": 3}], "meta": {"note": "hi"}}';
        $converted = JsonTree::convert(json_decode($json, associative: false));
        self::assertInstanceOf(JsonObject::class, $converted);

        $request = Hydrator::hydrate(
            AbsentUnionShapesRequest::class,
            $converted->toArray(),
            source: InputSource::Json,
        );

        self::assertSame(2, $request->quantity);
        self::assertInstanceOf(OrderItem::class, $request->item);
        self::assertCount(1, $request->items);
        self::assertSame(['note' => 'hi'], $request->meta);

        $empty = Hydrator::hydrate(AbsentUnionShapesRequest::class, [], source: InputSource::Json);

        self::assertSame(Absent::Value, $empty->quantity);
        self::assertSame(Absent::Value, $empty->item);
        self::assertSame(Absent::Value, $empty->items);
        self::assertSame(Absent::Value, $empty->meta);
    }

    /**
     * No wire format can spell the marker, but a Native caller holds real
     * PHP values and could hand one over. It is read as the wrong value
     * for the field, never as the omission it would otherwise imitate.
     */
    public function test_supplied_data_cannot_inject_the_absent_marker(): void
    {
        try {
            Hydrator::hydrate(UpdateArticleRequest::class, ['title' => Absent::Value]);
            self::fail('Expected a supplied Absent marker to be refused.');
        } catch (ValidationException $e) {
            self::assertSame(['title'], $e->violations[0]->path);
            self::assertSame('type_mismatch', $e->violations[0]->code);
            self::assertSame(['expected' => 'string', 'given' => 'object'], $e->violations[0]->parameters);
        }
    }

    /**
     * The declaration the incidental type check would not catch:
     * `Absent::Value` genuinely is a `UnitEnum`, so a value type wide
     * enough to admit it would bind the marker as a real value and read
     * an omission the client never made.
     */
    public function test_the_marker_is_refused_even_where_the_value_type_would_admit_it(): void
    {
        self::assertInstanceOf(\UnitEnum::class, Absent::Value);

        $accepted = Hydrator::hydrate(AbsentEnumSupertypeRequest::class, ['choice' => SortDirection::Ascending]);
        self::assertSame(SortDirection::Ascending, $accepted->choice);

        try {
            Hydrator::hydrate(AbsentEnumSupertypeRequest::class, ['choice' => Absent::Value]);
            self::fail('Expected a supplied Absent marker to be refused.');
        } catch (ValidationException $e) {
            self::assertSame('type_mismatch', $e->violations[0]->code);
        }
    }

    /**
     * @return iterable<string, array{0: class-string, 1: string}>
     */
    public static function malformedUnions(): iterable
    {
        yield 'the marker with no value type beside it' => [
            AbsentWithoutValueTypeRequest::class,
            'needs exactly one value type beside it',
        ];

        yield 'two value types' => [
            AbsentWithTwoValueTypesRequest::class,
            'carries exactly one value type, and this one names int and string',
        ];

        yield 'no default at all' => [
            AbsentWithoutDefaultRequest::class,
            'must default to Absent::Value',
        ];

        yield 'a default other than the marker' => [
            AbsentWithWrongDefaultRequest::class,
            'must default to exactly Absent::Value, not null',
        ];
    }

    /**
     * Compiling the plan is where a definition fails, so the same four
     * declarations are refused whether the plan is built live on the
     * first request or ahead of time by `kinetis build`.
     *
     * @param class-string $class
     */
    #[DataProvider('malformedUnions')]
    public function test_a_malformed_presence_union_fails_when_its_plan_is_compiled(string $class, string $message): void
    {
        $this->expectException(UnsupportedDtoDefinitionException::class);
        $this->expectExceptionMessage($message);

        Hydrator::compilePlan($class);
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('malformedUnions')]
    public function test_a_malformed_presence_union_fails_on_a_live_hydration_too(string $class, string $message): void
    {
        $this->expectException(UnsupportedDtoDefinitionException::class);
        $this->expectExceptionMessage($message);

        Hydrator::hydrate($class, [], source: InputSource::Json);
    }

    /**
     * A union naming no Absent member is still the plain composite-type
     * rejection it always was — the presence union is an addition, not a
     * relaxation.
     */
    public function test_a_union_without_absent_is_still_rejected_as_a_composite_type(): void
    {
        $this->expectException(UnsupportedDtoDefinitionException::class);
        $this->expectExceptionMessage('no union but the T|Absent and T|null|Absent presence forms');

        Hydrator::compilePlan(Fixtures\UnionTypedFieldRequest::class);
    }

    /**
     * The published schema describes what a client can send: T, widened
     * with `null` only where the union names it, and never the marker.
     * The member is optional because the declaration carries a default —
     * the same rule every other defaulted field follows.
     */
    public function test_a_presence_field_publishes_its_value_type_and_never_the_marker(): void
    {
        $schema = JsonSchema::forClass(UpdateArticleRequest::class);

        self::assertSame(['type' => 'string'], $schema['properties']['title']);
        self::assertSame(['type' => ['string', 'null']], $schema['properties']['summary']);
        self::assertSame([], $schema['required']);
        self::assertStringNotContainsString('Absent', json_encode($schema, JSON_THROW_ON_ERROR));
    }

    public function test_a_presence_field_publishes_the_value_types_own_object_and_list_schemas(): void
    {
        $schema = JsonSchema::forClass(AbsentUnionShapesRequest::class);

        self::assertSame(['type' => 'integer'], $schema['properties']['quantity']);
        self::assertSame('object', $schema['properties']['item']['type']);
        self::assertSame('array', $schema['properties']['items']['type']);
        self::assertSame(
            ['type' => 'object', 'additionalProperties' => true],
            $schema['properties']['meta'],
        );
    }

    /**
     * The plan records the declaration rather than leaving it to be
     * guessed from a captured default, and describes the parameter by its
     * value type throughout.
     */
    public function test_a_compiled_plan_records_the_presence_union_and_its_value_type(): void
    {
        $plan = Hydrator::compilePlan(UpdateArticleRequest::class);

        self::assertTrue($plan['parameters'][0]['absent']);
        self::assertSame('string', $plan['parameters'][0]['scalarType']);
        self::assertFalse($plan['parameters'][0]['allowsNull']);
        self::assertSame(Absent::Value, $plan['parameters'][0]['defaultValue']);

        self::assertTrue($plan['parameters'][1]['absent']);
        self::assertTrue($plan['parameters'][1]['allowsNull']);

        self::assertFalse(Hydrator::compilePlan(CreateArticleRequest::class)['parameters'][0]['absent']);
    }
}
