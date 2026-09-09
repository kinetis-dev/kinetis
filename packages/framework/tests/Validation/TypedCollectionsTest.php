<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation;

use Kinetis\Cache\CacheStore;
use Kinetis\Cache\CommandCache;
use Kinetis\Cache\CompiledCache;
use Kinetis\Cache\EventCache;
use Kinetis\Cache\Exception\InvalidCacheArtifactException;
use Kinetis\Cache\HttpCache;
use Kinetis\Cache\PluginCache;
use Kinetis\Tests\Fixtures\UnreadableUploadedFile;
use Kinetis\Tests\Validation\Fixtures\DateListRequest;
use Kinetis\Tests\Validation\Fixtures\EachNotAConstraintRequest;
use Kinetis\Tests\Validation\Fixtures\EachOnADtoListRequest;
use Kinetis\Tests\Validation\Fixtures\EachRulesRequest;
use Kinetis\Tests\Validation\Fixtures\EachWithoutListOfRequest;
use Kinetis\Tests\Validation\Fixtures\EmptyBackedEnumListRequest;
use Kinetis\Tests\Validation\Fixtures\ListOfAnInterfaceRequest;
use Kinetis\Tests\Validation\Fixtures\ListOfMixedRequest;
use Kinetis\Tests\Validation\Fixtures\ListOfNothingRequest;
use Kinetis\Tests\Validation\Fixtures\ListOfTheBackedEnumInterfaceRequest;
use Kinetis\Tests\Validation\Fixtures\ListOfUploadedFilesRequest;
use Kinetis\Tests\Validation\Fixtures\ListPresenceRequest;
use Kinetis\Tests\Validation\Fixtures\OrderItem;
use Kinetis\Tests\Validation\Fixtures\Priority;
use Kinetis\Tests\Validation\Fixtures\TypedListsRequest;
use Kinetis\Validation\Absent;
use Kinetis\Validation\Constraints\FileExtension;
use Kinetis\Validation\Constraints\MinLength;
use Kinetis\Validation\Each;
use Kinetis\Validation\Exception\JsonSchemaException;
use Kinetis\Validation\Exception\UnsupportedDtoDefinitionException;
use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\Hydrator;
use Kinetis\Validation\InputSource;
use Kinetis\Validation\JsonObject;
use Kinetis\Validation\JsonSchema;
use Kinetis\Validation\JsonTree;
use Kinetis\Validation\ListOf;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;
use ReflectionFunction;

/**
 * #[ListOf] over each element family it admits, and the #[Each] rules
 * a scalar, backed-enum or uploaded-file list runs on every element.
 */
final class TypedCollectionsTest extends TestCase
{
    public function test_a_scalar_list_binds_every_element(): void
    {
        $dto = Hydrator::hydrate(
            TypedListsRequest::class,
            self::decodedBody('{"tags": ["alpha", "beta"], "scores": [3, 7]}'),
            null,
            InputSource::Json,
        );

        self::assertSame(['alpha', 'beta'], $dto->tags);
        self::assertSame([3, 7], $dto->scores);
    }

    public function test_an_element_of_the_wrong_json_type_fails_at_its_own_numeric_index(): void
    {
        try {
            Hydrator::hydrate(
                TypedListsRequest::class,
                self::decodedBody('{"tags": ["alpha", 2, "gamma"]}'),
                null,
                InputSource::Json,
            );
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertCount(1, $e->violations);
            self::assertSame(['tags', 1], $e->violations[0]->path);
            self::assertSame('type_mismatch', $e->violations[0]->code);
            self::assertSame(['expected' => 'string', 'given' => 'integer'], $e->violations[0]->parameters);
        }
    }

    /**
     * An element is resolved through the one shared raw-scalar path, so
     * it speaks the source's own vocabulary: a query string or form body
     * writes an integer as text, and a JSON body does not.
     */
    public function test_an_element_binds_in_its_sources_own_spelling(): void
    {
        $text = Hydrator::hydrate(TypedListsRequest::class, ['tags' => [], 'scores' => ['3', '7']], null, InputSource::Text);
        self::assertSame([3, 7], $text->scores);

        $native = Hydrator::hydrate(TypedListsRequest::class, ['tags' => [], 'scores' => [3, '7']], null, InputSource::Native);
        self::assertSame([3, 7], $native->scores);

        try {
            Hydrator::hydrate(
                TypedListsRequest::class,
                self::decodedBody('{"tags": [], "scores": ["3"]}'),
                null,
                InputSource::Json,
            );
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['scores', 0], $e->violations[0]->path);
            self::assertSame('type_mismatch', $e->violations[0]->code);
        }
    }

    public function test_every_bad_element_is_reported_not_only_the_first(): void
    {
        try {
            Hydrator::hydrate(
                TypedListsRequest::class,
                self::decodedBody('{"tags": [1, "beta", 3]}'),
                null,
                InputSource::Json,
            );
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame([['tags', 0], ['tags', 2]], array_column($e->violations, 'path'));
        }
    }

    /**
     * No element is nullable: a list declares one element type, and a
     * hole in it would reach the constructor as a value the field's own
     * type never promised.
     */
    public function test_a_null_element_is_rejected_at_its_own_index(): void
    {
        try {
            Hydrator::hydrate(
                TypedListsRequest::class,
                self::decodedBody('{"tags": ["alpha", null]}'),
                null,
                InputSource::Json,
            );
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['tags', 1], $e->violations[0]->path);
            self::assertSame('null_not_allowed', $e->violations[0]->code);
        }
    }

    public function test_a_backed_enum_list_binds_each_case_from_its_backing_value(): void
    {
        $dto = Hydrator::hydrate(
            TypedListsRequest::class,
            self::decodedBody('{"tags": [], "priorities": [1, 3]}'),
            null,
            InputSource::Json,
        );

        self::assertSame([Priority::Low, Priority::High], $dto->priorities);
    }

    public function test_an_unknown_backing_value_in_a_list_names_its_own_index(): void
    {
        try {
            Hydrator::hydrate(
                TypedListsRequest::class,
                self::decodedBody('{"tags": [], "priorities": [1, 9]}'),
                null,
                InputSource::Json,
            );
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['priorities', 1], $e->violations[0]->path);
            self::assertSame('enum_case', $e->violations[0]->code);
            self::assertSame(['choices' => [1, 2, 3]], $e->violations[0]->parameters);
        }
    }

    public function test_a_dto_list_still_hydrates_and_reports_its_nested_paths(): void
    {
        $dto = Hydrator::hydrate(
            TypedListsRequest::class,
            self::decodedBody('{"tags": [], "items": [{"product": "Widget", "quantity": 2}]}'),
            null,
            InputSource::Json,
        );

        self::assertInstanceOf(OrderItem::class, $dto->items[0]);

        try {
            Hydrator::hydrate(
                TypedListsRequest::class,
                self::decodedBody('{"tags": [], "items": [{"product": "Widget", "quantity": 0}]}'),
                null,
                InputSource::Json,
            );
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame([['items', 0, 'quantity']], array_column($e->violations, 'path'));
        }
    }

    // --- #[Each]: rules about one element, repeatable, and run only on
    // an element that resolved to its declared type. ---

    public function test_every_each_rule_runs_on_every_resolved_element(): void
    {
        $dto = Hydrator::hydrate(
            EachRulesRequest::class,
            self::decodedBody('{"codes": ["AB", "CDEF"]}'),
            null,
            InputSource::Json,
        );

        self::assertSame(['AB', 'CDEF'], $dto->codes);
    }

    public function test_item_rule_failures_aggregate_under_segmented_paths(): void
    {
        try {
            Hydrator::hydrate(
                EachRulesRequest::class,
                self::decodedBody('{"codes": ["ab", "AB", "ABCDE"]}'),
                null,
                InputSource::Json,
            );
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(
                [['codes', 0], ['codes', 2]],
                array_column($e->violations, 'path'),
            );
            self::assertSame('uppercase', $e->violations[0]->code);
            self::assertSame('length_between', $e->violations[1]->code);
            self::assertSame(['min' => 2, 'max' => 4], $e->violations[1]->parameters);
        }
    }

    /**
     * A rule describes a value of the declared type, so an element that
     * never reached one gets its type violation and nothing else.
     */
    public function test_an_element_of_the_wrong_type_never_reaches_its_own_rules(): void
    {
        try {
            Hydrator::hydrate(
                EachRulesRequest::class,
                self::decodedBody('{"codes": [7, "AB"]}'),
                null,
                InputSource::Json,
            );
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertCount(1, $e->violations);
            self::assertSame('type_mismatch', $e->violations[0]->code);
        }
    }

    /**
     * The two levels run in order: a rule about the whole list reads a
     * list that was actually built, so one bad element leaves
     * #[MinItems] nothing to count.
     */
    public function test_an_element_failure_suppresses_the_lists_own_rules(): void
    {
        try {
            Hydrator::hydrate(
                EachRulesRequest::class,
                self::decodedBody('{"codes": ["ab"]}'),
                null,
                InputSource::Json,
            );
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['uppercase'], array_column($e->violations, 'code'));
        }
    }

    public function test_the_lists_own_rules_run_once_every_element_succeeded(): void
    {
        try {
            Hydrator::hydrate(
                EachRulesRequest::class,
                self::decodedBody('{"codes": ["AB"]}'),
                null,
                InputSource::Json,
            );
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['codes'], $e->violations[0]->path);
            self::assertSame('min_items', $e->violations[0]->code);
        }
    }

    /**
     * A catalogue rule is an #[Each] rule like any other: it sees one
     * resolved element, and its violation is reported at that element's
     * own index rather than on the list.
     */
    public function test_a_catalogue_rule_runs_per_element_at_its_own_index(): void
    {
        try {
            Hydrator::hydrate(
                DateListRequest::class,
                self::decodedBody('{"dates": ["2024-02-29", "2023-02-29"]}'),
                null,
                InputSource::Json,
            );
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame([['dates', 1]], array_column($e->violations, 'path'));
            self::assertSame('date', $e->violations[0]->code);
        }
    }

    public function test_an_enum_item_rule_reads_the_resolved_case(): void
    {
        try {
            Hydrator::hydrate(
                EachRulesRequest::class,
                self::decodedBody('{"codes": ["AB", "CD"], "escalations": [3, 1]}'),
                null,
                InputSource::Json,
            );
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['escalations', 1], $e->violations[0]->path);
            self::assertSame('minimum_priority', $e->violations[0]->code);
        }
    }

    /**
     * Argument validity belongs to the rule's own constructor, exactly
     * as it does for a rule written on the field: #[Each] carries the
     * arguments and builds nothing when the plan is compiled.
     */
    public function test_an_item_rules_own_argument_check_happens_when_the_rule_is_built(): void
    {
        $fn = static function (
            #[ListOf('string')]
            #[Each(MinLength::class, -1)]
            array $tags,
        ) {};
        $parameter = new ReflectionFunction($fn)->getParameters()[0];

        $item = Hydrator::listItem($parameter, $parameter->getType());
        self::assertSame([['class' => MinLength::class, 'args' => [-1]]], $item['constraints']);

        $this->expectException(InvalidArgumentException::class);

        JsonSchema::forParameters([$parameter]);
    }

    // --- Declarations refused where the plan is compiled. ---

    public function test_each_without_list_of_is_a_definition_error(): void
    {
        $this->expectException(UnsupportedDtoDefinitionException::class);
        $this->expectExceptionMessage('this parameter declares no #[ListOf]');

        Hydrator::compilePlan(EachWithoutListOfRequest::class);
    }

    public function test_each_on_a_dto_list_is_a_definition_error(): void
    {
        $this->expectException(UnsupportedDtoDefinitionException::class);
        $this->expectExceptionMessage('#[Each] applies to scalar, backed-enum and uploaded-file elements');

        Hydrator::compilePlan(EachOnADtoListRequest::class);
    }

    public function test_each_naming_something_that_is_not_a_constraint_is_a_definition_error(): void
    {
        $this->expectException(UnsupportedDtoDefinitionException::class);
        $this->expectExceptionMessage('does not name a Kinetis\Validation\Constraint implementation');

        Hydrator::compilePlan(EachNotAConstraintRequest::class);
    }

    /**
     * @param class-string $request
     */
    #[DataProvider('unsupportedItemTypes')]
    public function test_an_item_type_outside_the_admitted_set_is_a_definition_error(string $request, string $named): void
    {
        $this->expectException(UnsupportedDtoDefinitionException::class);
        $this->expectExceptionMessage("#[ListOf(\"{$named}\")] names a type no element can have");

        Hydrator::compilePlan($request);
    }

    /**
     * @return iterable<string, array{class-string, string}>
     */
    public static function unsupportedItemTypes(): iterable
    {
        yield 'a builtin with no element vocabulary' => [ListOfMixedRequest::class, 'mixed'];
        yield 'an empty name' => [ListOfNothingRequest::class, ''];
        yield 'an interface' => [ListOfAnInterfaceRequest::class, 'Psr\Http\Message\StreamInterface'];
        // BackedEnum satisfies is_a() against itself while its
        // inherited cases() is abstract, so classifying it as a backed
        // enum asks the engine for cases it cannot produce.
        yield 'the BackedEnum interface itself' => [ListOfTheBackedEnumInterfaceRequest::class, 'BackedEnum'];
    }

    public function test_a_list_of_an_empty_backed_enum_is_a_definition_error(): void
    {
        $this->expectException(UnsupportedDtoDefinitionException::class);
        $this->expectExceptionMessage('is a backed enum with no cases');

        Hydrator::compilePlan(EmptyBackedEnumListRequest::class);
    }

    // --- The plan a build writes and a worker reuses. ---

    public function test_the_compiled_plan_holds_the_exact_item_descriptor(): void
    {
        $plan = Hydrator::compilePlan(EachRulesRequest::class);

        self::assertSame([
            'name', 'scalarType', 'enumClass', 'dtoClass', 'nestedPlan', 'listItem',
            'objectMap', 'absent', 'hasDefault', 'defaultValue', 'allowsNull', 'constraints',
        ], array_keys($plan['parameters'][0]));

        self::assertSame([
            'scalarType' => 'string',
            'enumClass' => null,
            'dtoClass' => null,
            'nestedPlan' => null,
            'constraints' => [
                ['class' => Fixtures\Uppercase::class, 'args' => []],
                ['class' => Fixtures\LengthBetween::class, 'args' => [2, 'max' => 4]],
            ],
        ], $plan['parameters'][0]['listItem']);

        self::assertSame([
            'scalarType' => 'int',
            'enumClass' => Priority::class,
            'dtoClass' => null,
            'nestedPlan' => null,
            'constraints' => [
                ['class' => Fixtures\MinimumPriority::class, 'args' => ['least' => 2]],
            ],
        ], $plan['parameters'][1]['listItem']);
    }

    /**
     * Plain data all the way down: a plan holds class names, literals
     * and nested plans, so an artifact can hold one and a worker can
     * reuse it for the whole process's lifetime.
     */
    public function test_the_compiled_plan_holds_nothing_but_plain_data(): void
    {
        self::assertSame(
            [],
            self::objectsIn(Hydrator::compilePlan(EachRulesRequest::class)),
        );
        self::assertSame(
            [],
            self::objectsIn(Hydrator::compilePlan(TypedListsRequest::class)),
        );
    }

    public function test_a_malformed_item_descriptor_fails_artifact_validation(): void
    {
        $plan = Hydrator::compilePlan(TypedListsRequest::class);
        $plan['parameters'][0]['listItem']['unexpected'] = true;

        $this->expectException(InvalidCacheArtifactException::class);
        $this->expectExceptionMessage('A compiled "HydrationPlanListItem" artifact has a malformed entry');

        Hydrator::validatePlans([TypedListsRequest::class => $plan]);
    }

    public function test_an_item_descriptor_that_is_not_an_array_fails_artifact_validation(): void
    {
        $plan = Hydrator::compilePlan(TypedListsRequest::class);
        $plan['parameters'][0]['listItem'] = 'string';

        $this->expectException(InvalidCacheArtifactException::class);
        $this->expectExceptionMessage('"listItem" field is not an array or null');

        Hydrator::validatePlans([TypedListsRequest::class => $plan]);
    }

    public function test_a_valid_typed_collection_plan_passes_artifact_validation(): void
    {
        $plan = Hydrator::compilePlan(TypedListsRequest::class);

        Hydrator::validatePlans([TypedListsRequest::class => $plan]);

        self::assertSame(TypedListsRequest::class, $plan['className']);
    }

    /**
     * A plan is derived once and reused for every request after it, so
     * hydration reads it and never writes to it.
     */
    public function test_repeated_hydrations_leave_the_plan_unchanged(): void
    {
        $plan = Hydrator::compilePlan(EachRulesRequest::class);
        $before = var_export($plan, true);
        $data = self::decodedBody('{"codes": ["AB", "CD"], "escalations": [2]}');

        $first = Hydrator::hydrate(EachRulesRequest::class, $data, $plan, InputSource::Json);
        $second = Hydrator::hydrate(EachRulesRequest::class, $data, $plan, InputSource::Json);
        $live = Hydrator::hydrate(EachRulesRequest::class, $data, null, InputSource::Json);

        self::assertSame($before, var_export($plan, true));
        self::assertEquals($first, $second);
        self::assertEquals($first, $live);
    }

    // --- Presence unions around each element family. ---

    public function test_a_presence_union_tells_an_omitted_list_from_an_empty_one(): void
    {
        $omitted = Hydrator::hydrate(ListPresenceRequest::class, [], null, InputSource::Json);

        self::assertSame(Absent::Value, $omitted->tags);
        self::assertSame(Absent::Value, $omitted->priorities);
        self::assertSame(Absent::Value, $omitted->items);

        $sent = Hydrator::hydrate(
            ListPresenceRequest::class,
            self::decodedBody('{"tags": [], "priorities": [2], "items": []}'),
            null,
            InputSource::Json,
        );

        self::assertSame([], $sent->tags);
        self::assertSame([Priority::Normal], $sent->priorities);
        self::assertSame([], $sent->items);
    }

    public function test_a_nullable_presence_list_still_accepts_an_explicit_null(): void
    {
        $cleared = Hydrator::hydrate(
            ListPresenceRequest::class,
            self::decodedBody('{"priorities": null}'),
            null,
            InputSource::Json,
        );

        self::assertNull($cleared->priorities);

        try {
            Hydrator::hydrate(ListPresenceRequest::class, self::decodedBody('{"tags": null}'), null, InputSource::Json);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame('null_not_allowed', $e->violations[0]->code);
        }
    }

    /**
     * The marker is what an omission produces, never what an input may
     * send — including for a list, whose own type would otherwise say
     * nothing about it.
     */
    public function test_a_presence_list_never_publishes_or_accepts_the_marker(): void
    {
        $schema = JsonSchema::forClass(ListPresenceRequest::class);

        self::assertSame('array', $schema['properties']['tags']['type']);
        self::assertSame(['array', 'null'], $schema['properties']['priorities']['type']);
        self::assertSame([], $schema['required']);

        try {
            Hydrator::hydrate(ListPresenceRequest::class, ['tags' => Absent::Value]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame('type_mismatch', $e->violations[0]->code);
        }
    }

    // --- What the same declarations publish. ---

    public function test_a_scalar_list_publishes_its_own_item_type(): void
    {
        $schema = JsonSchema::forClass(TypedListsRequest::class);

        self::assertSame(['type' => 'array', 'items' => ['type' => 'string']], $schema['properties']['tags']);
        self::assertSame(['type' => 'array', 'items' => ['type' => 'integer']], $schema['properties']['scores']);
    }

    public function test_an_enum_list_publishes_its_backing_type_and_exact_cases(): void
    {
        $schema = JsonSchema::forClass(TypedListsRequest::class);

        self::assertSame(
            ['type' => 'array', 'items' => ['type' => 'integer', 'enum' => [1, 2, 3]]],
            $schema['properties']['priorities'],
        );
    }

    /**
     * An element's rules merge onto the enum's own domain exactly as
     * they merge onto a scalar element's type, and exactly as a rule on
     * a backed-enum field merges onto that field's.
     */
    public function test_item_rule_keywords_merge_into_an_enum_item_schema(): void
    {
        $schema = JsonSchema::forClass(EachRulesRequest::class);

        self::assertSame(
            ['type' => 'array', 'items' => ['type' => 'integer', 'enum' => [1, 2, 3], 'minimum' => 2]],
            $schema['properties']['escalations'],
        );
    }

    public function test_a_dto_list_still_publishes_its_element_schema(): void
    {
        $schema = JsonSchema::forClass(
            TypedListsRequest::class,
            static fn (string $class): array => ['$ref' => "#/components/schemas/{$class}"],
        );

        self::assertSame(
            ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/' . OrderItem::class]],
            $schema['properties']['items'],
        );
    }

    /**
     * Each level's keywords land where that level's rules are enforced:
     * the list's bound on the array, each element's rules on `items`.
     */
    public function test_item_rule_keywords_merge_into_the_item_schema(): void
    {
        $schema = JsonSchema::forClass(EachRulesRequest::class);

        self::assertSame([
            'type' => 'array',
            'items' => ['type' => 'string', 'pattern' => '^[A-Z]+$', 'minLength' => 2, 'maxLength' => 4],
            'minItems' => 2,
        ], $schema['properties']['codes']);
    }

    public function test_two_item_rules_claiming_one_keyword_are_refused(): void
    {
        $fn = static function (
            #[ListOf('string')]
            #[Each(MinLength::class, 2)]
            #[Each(MinLength::class, 3)]
            array $tags,
        ) {};

        $this->expectException(JsonSchemaException::class);
        $this->expectExceptionMessage('both contribute the JSON Schema keyword "minLength"');

        JsonSchema::forParameters(new ReflectionFunction($fn)->getParameters());
    }

    public function test_an_item_rule_cannot_restate_the_element_type(): void
    {
        $fn = static function (
            #[ListOf('string')]
            #[Each(Fixtures\ClaimsKeyword::class, 'type', ['type' => 'integer'])]
            array $tags,
        ) {};

        $this->expectException(JsonSchemaException::class);
        $this->expectExceptionMessage('contributes the JSON Schema keyword "type"');

        JsonSchema::forParameters(new ReflectionFunction($fn)->getParameters());
    }

    public function test_an_item_rule_cannot_restate_an_enum_elements_own_cases(): void
    {
        $fn = static function (
            #[ListOf(Priority::class)]
            #[Each(Fixtures\ClaimsKeyword::class, 'enum', ['1'])]
            array $priorities,
        ) {};

        $this->expectException(JsonSchemaException::class);
        $this->expectExceptionMessage('contributes the JSON Schema keyword "enum"');

        JsonSchema::forParameters(new ReflectionFunction($fn)->getParameters());
    }

    /**
     * The two schema entry points — a #[Body] DTO's own class, and a
     * method parameter typed as that DTO, which is how an MCP tool
     * declares one — describe the identical field identically.
     */
    public function test_both_schema_entry_points_describe_one_list_the_same_way(): void
    {
        $fn = static function (TypedListsRequest $data) {};

        $viaClass = JsonSchema::forClass(TypedListsRequest::class);
        $viaParameters = JsonSchema::forParameters(new ReflectionFunction($fn)->getParameters());

        self::assertSame($viaClass, $viaParameters['properties']['data']);
    }

    // --- A list of uploaded files. The one non-instantiable element
    // class #[ListOf] admits, because a repeated file control is a real
    // multipart shape. ---

    public function test_an_upload_list_compiles_to_a_class_element_with_no_nested_plan(): void
    {
        $plan = Hydrator::compilePlan(ListOfUploadedFilesRequest::class);

        self::assertSame([
            'scalarType' => null,
            'enumClass' => null,
            'dtoClass' => UploadedFileInterface::class,
            // Nothing to hydrate an element from: the interface has no
            // constructor, and an element is an instance the transport
            // already built.
            'nestedPlan' => null,
            'constraints' => [['class' => FileExtension::class, 'args' => [['png']]]],
        ], $plan['parameters'][0]['listItem']);
    }

    public function test_an_upload_list_binds_every_file_and_runs_its_element_rule(): void
    {
        $dto = Hydrator::hydrate(ListOfUploadedFilesRequest::class, [
            'photos' => [self::upload('one.png'), self::upload('two.PNG')],
        ]);

        self::assertSame(['one.png', 'two.PNG'], array_map(
            static fn (UploadedFileInterface $file): ?string => $file->getClientFilename(),
            $dto->photos,
        ));

        try {
            Hydrator::hydrate(ListOfUploadedFilesRequest::class, ['photos' => [self::upload('one.gif')]]);
            self::fail('Expected the element rule to refuse the suffix.');
        } catch (ValidationException $e) {
            self::assertSame(['photos', 0], $e->violations[0]->path);
            self::assertSame('file_extension', $e->violations[0]->code);
        }
    }

    /**
     * The status gate comes before the rule. A file that did not arrive
     * has nothing a rule could describe, and its stream throws — which
     * is what the double here proves nothing reached for.
     */
    public function test_a_failed_element_reports_its_status_and_nothing_else(): void
    {
        try {
            Hydrator::hydrate(ListOfUploadedFilesRequest::class, [
                'photos' => [self::upload('one.png'), new UnreadableUploadedFile(UPLOAD_ERR_PARTIAL, null, 'x.txt')],
            ]);
            self::fail('Expected the failed element to report.');
        } catch (ValidationException $e) {
            self::assertCount(1, $e->violations);
            self::assertSame(['photos', 1], $e->violations[0]->path);
            self::assertSame('upload_failed', $e->violations[0]->code);
            self::assertSame(['error' => UPLOAD_ERR_PARTIAL], $e->violations[0]->parameters);
        }
    }

    public function test_an_element_that_is_no_file_at_all_reports_the_declared_interface(): void
    {
        try {
            Hydrator::hydrate(ListOfUploadedFilesRequest::class, ['photos' => ['one.png']]);
            self::fail('Expected the element to be refused.');
        } catch (ValidationException $e) {
            self::assertSame(['photos', 0], $e->violations[0]->path);
            self::assertSame('not_an_instance', $e->violations[0]->code);
        }
    }

    public function test_an_upload_list_publishes_a_binary_string_item(): void
    {
        $schema = JsonSchema::forClass(ListOfUploadedFilesRequest::class);

        self::assertSame(
            ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'binary']],
            $schema['properties']['photos'],
        );
    }

    /**
     * The same list, reached through a plan that was compiled, written
     * to a real artifact with var_export(), and required back — the
     * path a production request actually takes.
     */
    public function test_an_upload_list_survives_a_real_cache_write_and_reload(): void
    {
        $directory = sys_get_temp_dir() . '/kinetis_upload_list_cache_' . bin2hex(random_bytes(8));
        $store = new CacheStore($directory);

        try {
            $store->write(new CompiledCache(
                http: new HttpCache(
                    routes: [],
                    httpBindingPlans: [],
                    hydrationPlans: [
                        ListOfUploadedFilesRequest::class => Hydrator::compilePlan(ListOfUploadedFilesRequest::class),
                    ],
                    globalMiddleware: [],
                    openApiMiddleware: [],
                ),
                commands: new CommandCache([]),
                events: new EventCache([]),
                plugins: new PluginCache([]),
            ));

            $reloaded = $store->load();
            self::assertNotNull($reloaded);

            $plan = $reloaded->http->hydrationPlans[ListOfUploadedFilesRequest::class];
            self::assertSame(UploadedFileInterface::class, $plan['parameters'][0]['listItem']['dtoClass']);

            $dto = Hydrator::hydrate(
                ListOfUploadedFilesRequest::class,
                ['photos' => [self::upload('one.png')]],
                $plan,
            );

            self::assertSame('one.png', $dto->photos[0]->getClientFilename());
        } finally {
            foreach (glob($directory . '/*') ?: [] as $entry) {
                is_dir($entry) ? @rmdir($entry) : @unlink($entry);
            }

            @rmdir($directory);
        }
    }

    private static function upload(string $filename): UploadedFileInterface
    {
        return new UploadedFile(Stream::create('bytes'), 5, UPLOAD_ERR_OK, $filename, 'image/png');
    }

    /**
     * @param array<array-key, mixed> $plan
     * @return list<string>
     */
    private static function objectsIn(array $plan): array
    {
        $objects = [];

        array_walk_recursive($plan, static function (mixed $value) use (&$objects): void {
            if (is_object($value)) {
                $objects[] = get_debug_type($value);
            }
        });

        return $objects;
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
