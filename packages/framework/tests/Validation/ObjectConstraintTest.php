<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation;

use InvalidArgumentException;
use Kinetis\Cache\CacheStore;
use Kinetis\Cache\CommandCache;
use Kinetis\Cache\CompiledCache;
use Kinetis\Cache\EventCache;
use Kinetis\Cache\HttpCache;
use Kinetis\Cache\PluginCache;
use Kinetis\Tests\Validation\Fixtures\ChangePasswordRequest;
use Kinetis\Tests\Validation\Fixtures\DuplicateObjectKeywordRequest;
use Kinetis\Tests\Validation\Fixtures\ObjectRuleClaimsDeclaredKeywordRequest;
use Kinetis\Tests\Validation\Fixtures\ObjectRuleKeywordRequest;
use Kinetis\Tests\Validation\Fixtures\SameAsUnreadableFieldRequest;
use Kinetis\Tests\Validation\Fixtures\SuppliedFieldsRequest;
use Kinetis\Tests\Validation\Fixtures\TeamUpdateRequest;
use Kinetis\Tests\Validation\Fixtures\ThrowingObjectRuleRequest;
use Kinetis\Tests\Validation\Fixtures\UnknownObjectRuleFieldRequest;
use Kinetis\Tests\Validation\Fixtures\UpdateArticleRequest;
use Kinetis\Tests\Validation\Fixtures\YieldsNonViolationRequest;
use Kinetis\Validation\Exception\JsonSchemaException;
use Kinetis\Validation\Exception\UnsupportedDtoDefinitionException;
use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\Hydrator;
use Kinetis\Validation\InputSource;
use Kinetis\Validation\JsonObject;
use Kinetis\Validation\JsonSchema;
use Kinetis\Validation\JsonTree;
use Kinetis\Validation\ObjectConstraints\AtLeastOneProvided;
use Kinetis\Validation\ObjectConstraints\SameAs;
use Kinetis\Validation\ValidationContext;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * DTO-level rules: what they are told, when they run, what a broken one
 * does, and what they publish.
 */
final class ObjectConstraintTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function decodedBody(string $json): array
    {
        $converted = JsonTree::convert(json_decode($json, associative: false));
        self::assertInstanceOf(JsonObject::class, $converted);

        return $converted->toArray();
    }

    public function test_a_rule_is_told_which_fields_the_input_supplied(): void
    {
        try {
            Hydrator::hydrate(
                SuppliedFieldsRequest::class,
                self::decodedBody('{"first": "default", "third": null}'),
                source: InputSource::Json,
            );
            self::fail('Expected the recording rule to report.');
        } catch (ValidationException $e) {
            // `first` was sent with exactly the value its default already
            // holds, and `third` was sent as null — neither is visible in
            // the constructed object, and both are presence.
            self::assertSame(['fields' => ['first', 'third']], $e->violations[0]->parameters);
        }
    }

    public function test_a_rule_sees_no_fields_when_the_input_supplied_none(): void
    {
        try {
            Hydrator::hydrate(SuppliedFieldsRequest::class, [], source: InputSource::Json);
            self::fail('Expected the recording rule to report.');
        } catch (ValidationException $e) {
            self::assertSame(['fields' => []], $e->violations[0]->parameters);
        }
    }

    /**
     * An application rule works from a class attribute alone, with
     * nothing registered anywhere — the same discovery a field rule gets.
     */
    public function test_an_application_rule_runs_from_a_live_plan_with_no_registration(): void
    {
        $this->expectException(ValidationException::class);

        Hydrator::hydrate(SuppliedFieldsRequest::class, [], source: InputSource::Json);
    }

    /**
     * The same rule, reached through a plan that was compiled, written to
     * a real artifact with var_export(), and required back — the path a
     * production request actually takes.
     */
    public function test_an_application_rule_survives_a_real_cache_write_and_reload(): void
    {
        $directory = sys_get_temp_dir() . '/kinetis_object_rule_cache_' . bin2hex(random_bytes(8));
        $store = new CacheStore($directory);

        try {
            $store->write(new CompiledCache(
                http: new HttpCache(
                    routes: [],
                    httpBindingPlans: [],
                    hydrationPlans: [
                        SuppliedFieldsRequest::class => Hydrator::compilePlan(SuppliedFieldsRequest::class),
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

            $plan = $reloaded->http->hydrationPlans[SuppliedFieldsRequest::class];
            self::assertSame(
                [['class' => Fixtures\RecordsSuppliedFields::class, 'args' => ['first', 'second', 'third']]],
                $plan['objectRules'],
            );

            try {
                Hydrator::hydrate(
                    SuppliedFieldsRequest::class,
                    self::decodedBody('{"second": "x"}'),
                    $plan,
                    InputSource::Json,
                );
                self::fail('Expected the reloaded rule to report.');
            } catch (ValidationException $e) {
                self::assertSame(['fields' => ['second']], $e->violations[0]->parameters);
            }
        } finally {
            foreach (glob($directory . '/*') ?: [] as $entry) {
                is_dir($entry) ? @rmdir($entry) : @unlink($entry);
            }

            @rmdir($directory);
        }
    }

    /**
     * A rule's paths are relative to the DTO it guards, so the owner
     * prefixes its own field name or list index on the way out — the same
     * prefixing a nested field failure gets.
     */
    public function test_a_nested_dtos_rule_reports_under_its_owners_path(): void
    {
        try {
            Hydrator::hydrate(
                TeamUpdateRequest::class,
                self::decodedBody('{"lead": {}, "members": []}'),
                source: InputSource::Json,
            );
            self::fail('Expected the nested rule to fail.');
        } catch (ValidationException $e) {
            self::assertSame(['lead'], $e->violations[0]->path);
            self::assertSame('at_least_one_provided', $e->violations[0]->code);
        }

        try {
            Hydrator::hydrate(
                TeamUpdateRequest::class,
                self::decodedBody('{"lead": {"role": "owner"}, "members": [{"role": "dev"}, {}]}'),
                source: InputSource::Json,
            );
            self::fail('Expected the list element rule to fail.');
        } catch (ValidationException $e) {
            self::assertSame(['members', 1], $e->violations[0]->path);
        }
    }

    /**
     * After a field failure the only values a DTO could hold are its own
     * declaration's defaults, so there is nothing truthful for a
     * cross-field rule to describe — and the object was never built.
     */
    public function test_object_rules_do_not_run_after_a_field_failure(): void
    {
        try {
            Hydrator::hydrate(
                UpdateArticleRequest::class,
                self::decodedBody('{"title": 42}'),
                source: InputSource::Json,
            );
            self::fail('Expected the field failure to be reported.');
        } catch (ValidationException $e) {
            self::assertCount(1, $e->violations);
            self::assertSame('type_mismatch', $e->violations[0]->code);
        }
    }

    public function test_a_throwing_rule_is_a_server_failure_not_a_client_response(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the rule itself is broken');

        Hydrator::hydrate(ThrowingObjectRuleRequest::class, ['name' => 'x'], source: InputSource::Json);
    }

    public function test_a_rule_yielding_something_other_than_a_violation_is_a_definition_failure(): void
    {
        $this->expectException(UnsupportedDtoDefinitionException::class);
        $this->expectExceptionMessage('yielded string');

        Hydrator::hydrate(YieldsNonViolationRequest::class, ['name' => 'x'], source: InputSource::Json);
    }

    public function test_at_least_one_provided_rejects_an_update_that_says_nothing(): void
    {
        try {
            Hydrator::hydrate(UpdateArticleRequest::class, [], source: InputSource::Json);
            self::fail('Expected an empty update to be refused.');
        } catch (ValidationException $e) {
            self::assertSame([], $e->violations[0]->path);
            self::assertSame('at_least_one_provided', $e->violations[0]->code);
            self::assertSame('must provide at least one of: title, summary.', $e->violations[0]->message);
            self::assertSame(['fields' => ['title', 'summary']], $e->violations[0]->parameters);
        }
    }

    /**
     * `null` is something the client said. Where the declaration accepts
     * it, sending it is an update — clearing a field is a change.
     */
    public function test_at_least_one_provided_counts_an_explicit_allowed_null_as_provided(): void
    {
        $article = Hydrator::hydrate(
            UpdateArticleRequest::class,
            self::decodedBody('{"summary": null}'),
            source: InputSource::Json,
        );

        self::assertNull($article->summary);
    }

    public function test_at_least_one_provided_publishes_the_any_of_of_its_own_fields(): void
    {
        self::assertSame(
            [['required' => ['title']], ['required' => ['summary']]],
            JsonSchema::forClass(UpdateArticleRequest::class)['anyOf'],
        );
    }

    /**
     * @return iterable<string, array{0: list<string>, 1: string}>
     */
    public static function invalidAtLeastOneProvidedArguments(): iterable
    {
        yield 'no fields at all' => [[], 'at least one field name'];
        yield 'an empty name' => [['title', ''], 'must not be empty'];
        yield 'a repeated name' => [['title', 'title'], 'must be distinct'];
    }

    /**
     * @param list<string> $fields
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidAtLeastOneProvidedArguments')]
    public function test_at_least_one_provided_refuses_a_list_it_could_not_state(array $fields, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new AtLeastOneProvided(...$fields);
    }

    public function test_same_as_accepts_a_matching_pair_and_reports_a_mismatch_on_the_confirming_field(): void
    {
        $accepted = Hydrator::hydrate(
            ChangePasswordRequest::class,
            ['password' => 'hunter2', 'confirmation' => 'hunter2'],
            source: InputSource::Json,
        );

        self::assertSame('hunter2', $accepted->password());

        try {
            Hydrator::hydrate(
                ChangePasswordRequest::class,
                ['password' => 'hunter2', 'confirmation' => 'hunter3'],
                source: InputSource::Json,
            );
            self::fail('Expected the mismatch to be reported.');
        } catch (ValidationException $e) {
            self::assertSame(['confirmation'], $e->violations[0]->path);
            self::assertSame('same_as', $e->violations[0]->code);
            self::assertSame('must match password.', $e->violations[0]->message);
            self::assertSame(['other' => 'password'], $e->violations[0]->parameters);
        }
    }

    /**
     * Whether the confirming field had to be there is the declaration's
     * question, not this rule's: an omitted one is not a mismatch against
     * a default the client never sent.
     */
    public function test_same_as_compares_nothing_unless_both_fields_were_supplied(): void
    {
        $request = Hydrator::hydrate(
            ChangePasswordRequest::class,
            ['password' => 'hunter2'],
            source: InputSource::Json,
        );

        self::assertSame('hunter2', $request->password());
    }

    public function test_same_as_reads_non_public_promoted_fields(): void
    {
        $reflection = new \ReflectionProperty(ChangePasswordRequest::class, 'password');

        self::assertTrue($reflection->isPrivate());
        self::assertTrue($reflection->isPromoted());

        $this->expectException(ValidationException::class);

        Hydrator::hydrate(
            ChangePasswordRequest::class,
            ['password' => 'a', 'confirmation' => 'b'],
            source: InputSource::Json,
        );
    }

    public function test_same_as_naming_a_field_it_cannot_read_is_a_definition_failure(): void
    {
        $this->expectException(UnsupportedDtoDefinitionException::class);
        $this->expectExceptionMessage('the class declares no such property');

        Hydrator::hydrate(
            SameAsUnreadableFieldRequest::class,
            ['email' => 'a@example.com', 'confirmation' => 'a@example.com'],
            source: InputSource::Json,
        );
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function requestsOmittingAnUnreadableField(): iterable
    {
        yield 'the unreadable field omitted' => [['email' => 'a@example.com']];
        yield 'the readable field omitted' => [['confirmation' => 'a@example.com']];
        yield 'both omitted' => [[]];
    }

    /**
     * The comparison is what presence gates, not the reads: a request
     * skipping either member must not be what decides whether a rule
     * naming something the class cannot hold is noticed.
     *
     * @param array<string, mixed> $data
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('requestsOmittingAnUnreadableField')]
    public function test_same_as_reports_an_unreadable_field_even_when_a_name_was_omitted(array $data): void
    {
        $this->expectException(UnsupportedDtoDefinitionException::class);
        $this->expectExceptionMessage('the class declares no such property');

        Hydrator::hydrate(SameAsUnreadableFieldRequest::class, $data, source: InputSource::Json);
    }

    /**
     * A name the constructor does not declare is caught where the rules
     * are read, before a plan exists — so no request ever reaches a rule
     * that could only ever fail.
     */
    public function test_an_object_rule_naming_an_unknown_field_fails_when_the_plan_is_compiled(): void
    {
        $this->expectException(UnsupportedDtoDefinitionException::class);
        $this->expectExceptionMessage('names field "titel", which is not a constructor parameter');

        Hydrator::compilePlan(UnknownObjectRuleFieldRequest::class);
    }

    /**
     * The same declaration, reached through the other collector call
     * site: an unsatisfiable `anyOf` under a closed object would turn
     * every valid request into a client failure, so the document is
     * refused rather than published.
     */
    public function test_an_object_rule_naming_an_unknown_field_fails_when_the_schema_is_generated(): void
    {
        $this->expectException(UnsupportedDtoDefinitionException::class);
        $this->expectExceptionMessage('names field "titel", which is not a constructor parameter');

        JsonSchema::forClass(UnknownObjectRuleFieldRequest::class);
    }

    /**
     * The names a rule publishes are the ones it was configured with —
     * what the collector checks, and what a whole-object rule leaves
     * empty.
     */
    public function test_a_rule_reports_the_fields_it_relates(): void
    {
        self::assertSame(['title', 'summary'], new AtLeastOneProvided('title', 'summary')->fields());
        self::assertSame(['confirmation', 'password'], new SameAs('confirmation', 'password')->fields());
        self::assertSame([], new Fixtures\ThrowsFromRule()->fields());
    }

    public function test_same_as_refuses_arguments_it_could_not_compare(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('two different fields');

        new SameAs('password', 'password');
    }

    /**
     * No JSON Schema keyword compares two properties' values, so the rule
     * publishes nothing rather than something weaker.
     */
    public function test_same_as_contributes_no_schema_keywords(): void
    {
        self::assertSame([], new SameAs('confirmation', 'password')->schema());
        self::assertSame(
            ['type', 'properties', 'required', 'additionalProperties'],
            array_keys(JsonSchema::forClass(ChangePasswordRequest::class)),
        );
    }

    public function test_an_object_rules_keywords_are_merged_into_the_classes_schema(): void
    {
        self::assertSame('one', JsonSchema::forClass(ObjectRuleKeywordRequest::class)['minProperties']);
    }

    public function test_an_object_rule_cannot_replace_a_keyword_the_declaration_owns(): void
    {
        $this->expectException(JsonSchemaException::class);
        $this->expectExceptionMessage('additionalProperties');

        JsonSchema::forClass(ObjectRuleClaimsDeclaredKeywordRequest::class);
    }

    public function test_two_object_rules_cannot_claim_the_same_keyword(): void
    {
        $this->expectException(JsonSchemaException::class);
        $this->expectExceptionMessage('minProperties');

        JsonSchema::forClass(DuplicateObjectKeywordRequest::class);
    }

    /**
     * The memoized plan is what every later request reads, so it has to
     * come back from a hydration byte-for-byte identical — carrying no
     * context, no DTO, no violation and no rule instance.
     */
    public function test_repeated_hydration_leaves_the_memoized_plan_plain_and_unchanged(): void
    {
        $before = Hydrator::compilePlan(UpdateArticleRequest::class);

        Hydrator::hydrate(UpdateArticleRequest::class, ['title' => 'one'], source: InputSource::Json);
        Hydrator::hydrate(UpdateArticleRequest::class, ['title' => 'two'], source: InputSource::Json);

        $after = Hydrator::compilePlan(UpdateArticleRequest::class);

        self::assertSame(var_export($before, true), var_export($after, true));
        self::assertSame(
            [['class' => AtLeastOneProvided::class, 'args' => ['title', 'summary']]],
            $after['objectRules'],
        );
    }

    public function test_a_context_reports_only_the_fields_it_was_given(): void
    {
        $context = new ValidationContext(['title']);

        self::assertTrue($context->wasSupplied('title'));
        self::assertFalse($context->wasSupplied('summary'));
    }
}
