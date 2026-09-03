<?php

declare(strict_types=1);

namespace Kinetis\Tests\OpenApi;

use Kinetis\Http\Routing\Router;
use Kinetis\OpenApi\OpenApiGenerator;
use Kinetis\Tests\Http\Fixtures\BuiltinCoverageController;
use Kinetis\Tests\Http\Fixtures\ConstrainedParametersController;
use Kinetis\Tests\Http\Fixtures\HiddenController;
use Kinetis\Tests\Http\Fixtures\NullableFieldsController;
use Kinetis\Tests\Http\Fixtures\OrderController;
use Kinetis\Tests\Http\Fixtures\OrderItemsController;
use Kinetis\Tests\Http\Fixtures\PaginatedOrderController;
use Kinetis\Tests\Http\Fixtures\PlainArrayFieldController;
use Kinetis\Tests\Http\Fixtures\SameStatusResponseController;
use Kinetis\Tests\Http\Fixtures\UnsupportedBodyFieldController;
use Kinetis\Tests\Http\Fixtures\UnsupportedCallableBodyFieldController;
use Kinetis\Tests\Http\Fixtures\UploadController;
use Kinetis\Tests\Http\Fixtures\UserController;
use Kinetis\Tests\Reflection\Fixtures\HiddenChildOfRoutedBase;
use Kinetis\Validation\Exception\JsonSchemaException;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixtures/global_namespace_dto.php';

final class OpenApiGeneratorTest extends TestCase
{
    private function generate(): array
    {
        $router = new Router();
        $router->register(UserController::class);

        return (new OpenApiGenerator($router))->generate();
    }

    private function generateWithOrders(): array
    {
        $router = new Router();
        $router->register(UserController::class);
        $router->register(OrderController::class);

        return (new OpenApiGenerator($router))->generate();
    }

    /**
     * A class in the global namespace has no separator to split on, and
     * the naive offset arithmetic that finds one used to drop the first
     * character — `GlobalNamespaceDto` became `lobalNamespaceDto`, a
     * schema name silently unlike the class it describes.
     */
    public function test_a_dto_in_the_global_namespace_keeps_its_whole_name(): void
    {
        $router = new Router();
        $router->register(\GlobalNamespaceController::class);
        $spec = (new OpenApiGenerator($router))->generate();

        self::assertArrayHasKey('GlobalNamespaceDto', $spec['components']['schemas']);
        self::assertSame(
            ['$ref' => '#/components/schemas/GlobalNamespaceDto'],
            $spec['paths']['/global-dto']['get']['responses']['200']['content']['application/json']['schema'],
        );
    }

    public function test_generates_a_path_entry_for_every_registered_route(): void
    {
        $spec = $this->generate();

        self::assertArrayHasKey('/users', $spec['paths']);
        self::assertArrayHasKey('post', $spec['paths']['/users']);
        self::assertArrayHasKey('get', $spec['paths']['/users']);
        self::assertArrayHasKey('/users/{id}', $spec['paths']);
        self::assertArrayHasKey('/users/{id}/status', $spec['paths']);
    }

    public function test_describes_a_body_bound_dto_as_a_dollar_ref_into_components_schemas(): void
    {
        $spec = $this->generate();

        $ref = $spec['paths']['/users']['post']['requestBody']['content']['application/json']['schema'];
        self::assertSame(['$ref' => '#/components/schemas/CreateUserRequest'], $ref);

        $schema = $spec['components']['schemas']['CreateUserRequest'];
        self::assertSame('object', $schema['type']);
        self::assertSame(['name', 'email'], $schema['required']);
        self::assertSame(3, $schema['properties']['name']['minLength']);
        self::assertSame('email', $schema['properties']['email']['format']);
    }

    public function test_describes_the_default_responses_schema_from_the_controller_methods_return_type(): void
    {
        $spec = $this->generate();

        $ref = $spec['paths']['/users']['post']['responses']['201']['content']['application/json']['schema'];
        self::assertSame(['$ref' => '#/components/schemas/UserResponse'], $ref);

        $schema = $spec['components']['schemas']['UserResponse'];
        self::assertSame(['name', 'email'], $schema['required']);
    }

    public function test_a_union_return_type_with_no_schema_producing_member_gets_no_response_content(): void
    {
        $spec = $this->generate();

        // showOrNotFound(): ResponseInterface|array — neither member has a
        // fixed shape reflection alone can recover, so the 200 response
        // stays description-only, exactly like before this feature existed.
        self::assertArrayNotHasKey('content', $spec['paths']['/users/{id}/maybe']['get']['responses']['200']);
    }

    public function test_a_plain_array_return_type_gets_no_response_content(): void
    {
        $spec = $this->generate();

        self::assertArrayNotHasKey('content', $spec['paths']['/users']['get']['responses']['200']);
    }

    public function test_a_nested_dtos_property_is_itself_a_dollar_ref_not_inlined(): void
    {
        $spec = $this->generateWithOrders();

        $requestSchema = $spec['components']['schemas']['CreateOrderRequest'];
        self::assertSame(
            ['$ref' => '#/components/schemas/Address'],
            $requestSchema['properties']['shippingAddress'],
        );

        $addressSchema = $spec['components']['schemas']['Address'];
        self::assertSame('object', $addressSchema['type']);
        self::assertSame(3, $addressSchema['properties']['street']['minLength']);
    }

    public function test_the_same_nested_dto_reached_from_two_different_schemas_dedupes_to_one_entry(): void
    {
        $spec = $this->generateWithOrders();

        // Address is reachable both from CreateOrderRequest (the request
        // body) and OrderResponse (the response, via the return type) —
        // both must point at the exact same components/schemas entry.
        $requestRef = $spec['components']['schemas']['CreateOrderRequest']['properties']['shippingAddress'];
        $responseRef = $spec['components']['schemas']['OrderResponse']['properties']['shippingAddress'];

        self::assertSame(['$ref' => '#/components/schemas/Address'], $requestRef);
        self::assertSame($requestRef, $responseRef);
        self::assertCount(1, array_filter(array_keys($spec['components']['schemas']), static fn ($name) => $name === 'Address'));
    }

    public function test_describes_query_parameters_as_optional_when_they_have_defaults(): void
    {
        $spec = $this->generate();

        $parameters = $spec['paths']['/users']['get']['parameters'];
        $byName = array_column($parameters, null, 'name');

        self::assertSame('query', $byName['page']['in']);
        self::assertFalse($byName['page']['required']);
        self::assertSame('integer', $byName['page']['schema']['type']);
    }

    public function test_describes_path_parameters_as_required(): void
    {
        $spec = $this->generate();

        $parameters = $spec['paths']['/users/{id}']['get']['parameters'];

        self::assertSame('id', $parameters[0]['name']);
        self::assertSame('path', $parameters[0]['in']);
        self::assertTrue($parameters[0]['required']);
        self::assertSame('integer', $parameters[0]['schema']['type']);
    }

    public function test_a_query_parameters_constraint_is_reflected_in_its_schema(): void
    {
        $router = new Router();
        $router->register(ConstrainedParametersController::class);
        $spec = (new OpenApiGenerator($router))->generate();

        $parameters = $spec['paths']['/probe']['get']['parameters'];
        $byName = array_column($parameters, null, 'name');

        self::assertSame('integer', $byName['page']['schema']['type']);
        self::assertSame(0, $byName['page']['schema']['exclusiveMinimum']);
        self::assertSame(['asc', 'desc'], $byName['sort']['schema']['enum']);
    }

    public function test_a_path_parameters_constraint_is_reflected_in_its_schema(): void
    {
        $router = new Router();
        $router->register(ConstrainedParametersController::class);
        $spec = (new OpenApiGenerator($router))->generate();

        $parameters = $spec['paths']['/items/{code}']['get']['parameters'];

        self::assertSame('code', $parameters[0]['name']);
        self::assertSame('#^[A-Z]{3}$#', $parameters[0]['schema']['pattern']);
    }

    public function test_a_route_placeholder_constraint_is_stripped_from_the_path_key_but_kept_in_the_schema(): void
    {
        // Distinct from the attribute-based constraint above: this is the
        // {id:\d+} route-template syntax itself, which OpenAPI's own path
        // templating has no concept of — the constraint has to move into
        // the parameter's schema, and the path key has to go back to
        // plain {id}.
        $router = new Router();
        $router->register(ConstrainedParametersController::class);
        $spec = (new OpenApiGenerator($router))->generate();

        self::assertArrayHasKey('/products/{id}', $spec['paths']);
        self::assertArrayNotHasKey('/products/{id:\d+}', $spec['paths']);

        $parameters = $spec['paths']['/products/{id}']['get']['parameters'];
        self::assertSame('id', $parameters[0]['name']);
        self::assertSame('\d+', $parameters[0]['schema']['pattern']);
    }

    public function test_uses_the_route_configured_status_code_in_responses(): void
    {
        $spec = $this->generate();

        self::assertArrayHasKey('201', $spec['paths']['/users']['post']['responses']);
        self::assertArrayHasKey('200', $spec['paths']['/users']['get']['responses']);
    }

    public function test_describes_additional_response_attributes_alongside_the_route_default(): void
    {
        $spec = $this->generate();

        $responses = $spec['paths']['/users/{id}/maybe']['get']['responses'];

        self::assertArrayHasKey('200', $responses);
        self::assertArrayHasKey('404', $responses);
        self::assertSame('User not found.', $responses['404']['description']);
    }

    /**
     * The route's own status is described from the return type, schema
     * included. An attribute repeating that status is ignored rather
     * than replacing the entry with a description and no schema, which
     * would also leave the component it referenced defined and unused.
     */
    public function test_a_response_attribute_repeating_the_routes_own_status_is_ignored(): void
    {
        $router = new Router();
        $router->register(SameStatusResponseController::class);
        $spec = (new OpenApiGenerator($router))->generate();

        $responses = $spec['paths']['/echo']['get']['responses'];

        self::assertSame('Successful response', $responses['200']['description']);
        self::assertSame(
            ['$ref' => '#/components/schemas/UserResponse'],
            $responses['200']['content']['application/json']['schema'],
        );

        // The other statuses are additional, and still described.
        self::assertSame('Not found.', $responses['404']['description']);
        self::assertArrayNotHasKey('content', $responses['404']);
    }

    /**
     * The same rule for a route whose status is not the 200 default, so
     * the comparison is against the route's actual status rather than a
     * hardcoded one.
     */
    public function test_the_rule_holds_for_a_route_with_an_explicit_status(): void
    {
        $router = new Router();
        $router->register(SameStatusResponseController::class);
        $spec = (new OpenApiGenerator($router))->generate();

        $responses = $spec['paths']['/echo-created']['post']['responses'];

        self::assertSame('Successful response', $responses['201']['description']);
        self::assertArrayHasKey('content', $responses['201']);
        self::assertSame('Validation failed.', $responses['422']['description']);
    }

    public function test_a_list_of_property_is_a_dollar_ref_array_not_an_inlined_bare_object(): void
    {
        $router = new Router();
        $router->register(OrderItemsController::class);
        $spec = (new OpenApiGenerator($router))->generate();

        $requestSchema = $spec['components']['schemas']['OrderWithItems'];
        self::assertSame(
            ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/OrderItem']],
            $requestSchema['properties']['items'],
        );

        $itemSchema = $spec['components']['schemas']['OrderItem'];
        self::assertSame('object', $itemSchema['type']);
        self::assertArrayHasKey('product', $itemSchema['properties']);
        self::assertSame(0, $itemSchema['properties']['quantity']['exclusiveMinimum']);
    }

    public function test_a_list_items_dto_reached_from_request_and_response_dedupes_to_one_entry(): void
    {
        $router = new Router();
        $router->register(OrderItemsController::class);
        $spec = (new OpenApiGenerator($router))->generate();

        // OrderWithItems is used as both the request body and (via the
        // fixture's echo-back return type) the response schema — OrderItem
        // reached through either one must resolve to the same component.
        $requestRef = $spec['components']['schemas']['OrderWithItems']['properties']['items']['items'];
        self::assertSame(['$ref' => '#/components/schemas/OrderItem'], $requestRef);
        self::assertCount(1, array_filter(array_keys($spec['components']['schemas']), static fn ($name) => $name === 'OrderItem'));
    }

    public function test_a_hidden_method_is_excluded_from_the_generated_document(): void
    {
        $spec = $this->generate();

        self::assertArrayNotHasKey('/users/dashboard', $spec['paths']);
    }

    public function test_a_hidden_controller_class_excludes_every_one_of_its_routes(): void
    {
        $router = new Router();
        $router->register(HiddenController::class);

        $spec = (new OpenApiGenerator($router))->generate();

        self::assertArrayNotHasKey('/internal/status', $spec['paths']);

        // The hidden route's own #[Body] DTO must never register a
        // components/schemas entry either — describeOperation() (the only
        // place that happens) never runs for a hidden route.
        self::assertArrayNotHasKey('components', $spec);
    }

    public function test_a_paginated_item_attribute_describes_data_as_an_array_of_the_named_dto(): void
    {
        $router = new Router();
        $router->register(PaginatedOrderController::class);
        $spec = (new OpenApiGenerator($router))->generate();

        $schema = $spec['paths']['/orders/paginated']['get']['responses']['200']['content']['application/json']['schema'];

        self::assertSame(
            ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/OrderResponse']],
            $schema['properties']['data'],
        );
        self::assertSame(['type' => 'integer'], $schema['properties']['total']);
        self::assertArrayHasKey('OrderResponse', $spec['components']['schemas']);
    }

    public function test_a_paginated_item_attribute_works_for_cursor_paginator_too(): void
    {
        $router = new Router();
        $router->register(PaginatedOrderController::class);
        $spec = (new OpenApiGenerator($router))->generate();

        $schema = $spec['paths']['/orders/cursor']['get']['responses']['200']['content']['application/json']['schema'];

        self::assertSame(
            ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/OrderResponse']],
            $schema['properties']['data'],
        );
        self::assertSame(['type' => 'boolean'], $schema['properties']['hasMore']);
        self::assertArrayNotHasKey('total', $schema['properties']);
    }

    /**
     * KINETIS-76: a bare `array $data` property's own type is now the
     * truthful `array`, not the `object` fallback every unmapped builtin
     * used to collapse into.
     */
    public function test_a_paginator_without_the_attribute_keeps_the_bare_array_fallback(): void
    {
        $router = new Router();
        $router->register(PaginatedOrderController::class);
        $spec = (new OpenApiGenerator($router))->generate();

        $ref = $spec['paths']['/orders/paginated-bare']['get']['responses']['200']['content']['application/json']['schema'];

        self::assertSame(['$ref' => '#/components/schemas/Paginator'], $ref);
        self::assertSame(['type' => 'array'], $spec['components']['schemas']['Paginator']['properties']['data']);
    }

    /**
     * #[Hidden] is read from the class a route is registered on, not from
     * the class that declares its method. Router::register() no longer
     * accepts an inherited routed method at all, so this goes through
     * fromArray() — the path a compiled cache loads through — to reach the
     * distinction directly.
     */
    public function test_hidden_is_read_from_the_registered_class_not_the_declaring_one(): void
    {
        $router = Router::fromArray([[
            'httpMethod' => 'GET',
            'pathTemplate' => '/from-parent',
            'controllerClass' => HiddenChildOfRoutedBase::class,
            'controllerMethod' => 'fromParent',
            'status' => 200,
            'middleware' => [],
        ]]);

        $document = new OpenApiGenerator($router)->generate();

        // The declaring class carries no #[Hidden]; the registered one does.
        self::assertSame([], $document['paths']);
    }

    // KINETIS-75: the full generator pipeline — request-body dedup into
    // components/schemas included — must produce the identical nullable
    // representation JsonSchemaTest already proves at the unit level.

    public function test_a_nullable_body_field_is_widened_to_include_null_and_stays_required_without_a_default(): void
    {
        $router = new Router();
        $router->register(NullableFieldsController::class);
        $document = (new OpenApiGenerator($router))->generate();

        $ref = $document['paths']['/nullable-fields']['post']['requestBody']['content']['application/json']['schema'];
        self::assertSame(['$ref' => '#/components/schemas/NullableFieldsRequest'], $ref);

        $schema = $document['components']['schemas']['NullableFieldsRequest'];

        self::assertSame(['type' => ['string', 'null']], $schema['properties']['requiredNullable']);
        self::assertSame(['requiredNullable'], $schema['required'], 'only the defaultless field is required');
    }

    public function test_a_nullable_body_field_with_a_default_is_optional_and_widened(): void
    {
        $router = new Router();
        $router->register(NullableFieldsController::class);
        $document = (new OpenApiGenerator($router))->generate();

        $schema = $document['components']['schemas']['NullableFieldsRequest'];

        self::assertSame(['type' => ['string', 'null']], $schema['properties']['optionalNullable']);
        self::assertNotContains('optionalNullable', $schema['required']);
    }

    public function test_a_nullable_nested_dto_body_field_dedupes_to_an_any_of_wrapped_ref(): void
    {
        $router = new Router();
        $router->register(NullableFieldsController::class);
        $document = (new OpenApiGenerator($router))->generate();

        $schema = $document['components']['schemas']['NullableFieldsRequest'];

        self::assertSame(
            ['anyOf' => [['$ref' => '#/components/schemas/OrderItem'], ['type' => 'null']]],
            $schema['properties']['optionalItem'],
        );
        self::assertArrayHasKey('OrderItem', $document['components']['schemas']);
        self::assertNotContains('optionalItem', $schema['required']);
    }

    public function test_a_nullable_list_of_body_field_widens_the_arrays_own_type_and_keeps_a_ref_array(): void
    {
        $router = new Router();
        $router->register(NullableFieldsController::class);
        $document = (new OpenApiGenerator($router))->generate();

        $schema = $document['components']['schemas']['NullableFieldsRequest'];
        $itemsSchema = $schema['properties']['optionalItems'];

        self::assertSame(['array', 'null'], $itemsSchema['type']);
        self::assertSame(['$ref' => '#/components/schemas/OrderItem'], $itemsSchema['items']);
        self::assertNotContains('optionalItems', $schema['required']);
    }

    // KINETIS-76 follow-up: the complete, audited builtin-type policy —
    // see JsonSchema::forType()'s own docblock — proven end-to-end
    // through a real registered HTTP route's own generated OpenAPI
    // document, not just via JsonSchema unit calls.

    public function test_a_body_dto_schema_covers_every_supported_builtin_category(): void
    {
        $router = new Router();
        $router->register(BuiltinCoverageController::class);
        $document = (new OpenApiGenerator($router))->generate();

        $ref = $document['paths']['/builtin-coverage']['post']['requestBody']['content']['application/json']['schema'];
        self::assertSame(['$ref' => '#/components/schemas/BuiltinCoverageRequest'], $ref);

        $schema = $document['components']['schemas']['BuiltinCoverageRequest'];

        self::assertSame(['type' => 'array'], $schema['properties']['tags']);
        self::assertSame(['type' => 'array'], $schema['properties']['items'], 'iterable gets the identical array schema as plain array');
        self::assertEquals((object) [], $schema['properties']['note'], 'mixed is the empty schema object, not the empty schema array');
        self::assertSame(['type' => 'null'], $schema['properties']['marker']);
        self::assertSame(['type' => 'boolean', 'const' => true], $schema['properties']['confirmed']);
        self::assertSame(['type' => 'boolean', 'const' => false], $schema['properties']['declined']);
        self::assertSame(['tags', 'items'], $schema['required']);
    }

    /**
     * The real, serialized wire shape — not just object-identity on the
     * in-memory schema array. A bare `[]` for `mixed`'s schema would
     * json_encode() as an invalid JSON *array* (`"note":[]`) where JSON
     * Schema requires an object; this pins the actual bytes so that
     * regression can never silently reappear.
     */
    public function test_the_serialized_openapi_document_encodes_mixed_as_a_json_object_not_an_array(): void
    {
        $router = new Router();
        $router->register(BuiltinCoverageController::class);
        $document = (new OpenApiGenerator($router))->generate();

        $encoded = json_encode($document, JSON_THROW_ON_ERROR);

        self::assertStringContainsString('"note":{}', $encoded);
        self::assertStringNotContainsString('"note":[]', $encoded);
    }

    /**
     * Schema generation still refuses to describe an `object`/`callable`-
     * typed field, unchanged from before — but this is deliberately not
     * the guarantee that keeps a real request from reaching the
     * constructor unchecked; that guarantee is Hydrator::typeMismatchMessage(),
     * proven at the runtime/dispatch level in DispatcherTest, which fires
     * on every request regardless of whether generate() is ever called.
     */
    public function test_generate_still_throws_for_a_route_with_an_unsupported_builtin_body_field(): void
    {
        $router = new Router();
        $router->register(UnsupportedBodyFieldController::class);

        $this->expectException(JsonSchemaException::class);
        $this->expectExceptionMessage('object');

        (new OpenApiGenerator($router))->generate();
    }

    /**
     * `callable`'s own equivalent of the `object` test above — the
     * second rejected builtin category gets the identical breadth of
     * coverage, not just direct Hydrator/JsonSchema unit calls.
     */
    public function test_generate_still_throws_for_a_route_with_an_unsupported_callable_body_field(): void
    {
        $router = new Router();
        $router->register(UnsupportedCallableBodyFieldController::class);

        $this->expectException(JsonSchemaException::class);
        $this->expectExceptionMessage('callable');

        (new OpenApiGenerator($router))->generate();
    }

    /**
     * The requested end-to-end plain *nullable* array case — distinct
     * from #[ListOf]'s own nullable-array schema coverage
     * (test_a_nullable_list_of_body_field_widens_the_arrays_own_type_and_keeps_a_ref_array
     * above): a plain array's own schema has no `items` shape at all,
     * just the widened `type`.
     */
    public function test_a_nullable_plain_array_body_field_widens_its_type(): void
    {
        $router = new Router();
        $router->register(PlainArrayFieldController::class);
        $document = (new OpenApiGenerator($router))->generate();

        $schema = $document['components']['schemas']['PlainArrayFieldRequest'];

        self::assertSame(['type' => 'array'], $schema['properties']['tags']);
        self::assertSame(['type' => ['array', 'null']], $schema['properties']['optionalTags']);
        self::assertSame(['tags'], $schema['required']);
    }

    // KINETIS-76 third follow-up: Dispatcher::resolveBodyFromPlan()
    // branches purely on the real request's Content-Type header — any
    // #[Body] DTO genuinely accepts application/json,
    // application/x-www-form-urlencoded, and multipart/form-data alike,
    // unconditionally — so the generated document must advertise all
    // three, not just application/json.

    public function test_a_body_route_advertises_every_content_type_it_genuinely_accepts(): void
    {
        $router = new Router();
        $router->register(UserController::class);
        $document = (new OpenApiGenerator($router))->generate();

        $content = $document['paths']['/users']['post']['requestBody']['content'];

        self::assertSame(
            ['application/json', 'application/x-www-form-urlencoded', 'multipart/form-data'],
            array_keys($content),
        );

        // The identical schema (a $ref to the same component) under
        // every content type — Dispatcher hydrates the exact same DTO
        // class regardless of which of the three the client actually
        // sent, so there is nothing content-type-specific to differ.
        self::assertSame($content['application/json'], $content['application/x-www-form-urlencoded']);
        self::assertSame($content['application/json'], $content['multipart/form-data']);
    }

    /**
     * An UploadedFileInterface-typed #[Body] field has no constructor of
     * its own to expand into a schema (it's an interface, not a class
     * with fields) — {type: string, format: binary} is the real,
     * correct OpenAPI convention for a file upload inside a
     * multipart-serialized schema instead, telling a client the field's
     * true shape rather than the generic {type: object} a bare
     * "expand the constructor" fallback would otherwise produce.
     */
    public function test_an_uploaded_file_typed_body_field_gets_the_real_binary_string_schema(): void
    {
        $router = new Router();
        $router->register(UploadController::class);
        $document = (new OpenApiGenerator($router))->generate();

        $schema = $document['components']['schemas']['AvatarUploadRequest'];

        self::assertSame(['type' => 'string', 'format' => 'binary'], $schema['properties']['avatar']);
        self::assertSame(['name', 'avatar'], $schema['required']);
    }
}
