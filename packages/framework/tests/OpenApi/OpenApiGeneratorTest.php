<?php

declare(strict_types=1);

namespace Kinetis\Tests\OpenApi;

use Kinetis\Http\Routing\Router;
use Kinetis\OpenApi\OpenApiGenerator;
use Kinetis\Tests\Http\Fixtures\ConstrainedParametersController;
use Kinetis\Tests\Http\Fixtures\HiddenController;
use Kinetis\Tests\Http\Fixtures\OrderController;
use Kinetis\Tests\Http\Fixtures\OrderItemsController;
use Kinetis\Tests\Http\Fixtures\PaginatedOrderController;
use Kinetis\Tests\Http\Fixtures\UserController;
use PHPUnit\Framework\TestCase;

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

    public function test_a_paginator_without_the_attribute_keeps_the_bare_object_fallback(): void
    {
        $router = new Router();
        $router->register(PaginatedOrderController::class);
        $spec = (new OpenApiGenerator($router))->generate();

        $ref = $spec['paths']['/orders/paginated-bare']['get']['responses']['200']['content']['application/json']['schema'];

        self::assertSame(['$ref' => '#/components/schemas/Paginator'], $ref);
        self::assertSame(['type' => 'object'], $spec['components']['schemas']['Paginator']['properties']['data']);
    }
}
