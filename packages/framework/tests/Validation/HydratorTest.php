<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation;

use Kinetis\Tests\Http\Fixtures\Address;
use Kinetis\Tests\Http\Fixtures\AvatarUploadRequest;
use Kinetis\Tests\Http\Fixtures\CreateNoteRequest;
use Kinetis\Tests\Http\Fixtures\CreateOrderRequest;
use Kinetis\Tests\Http\Fixtures\CreateProductRequest;
use Kinetis\Tests\Http\Fixtures\CreateUserRequest;
use Kinetis\Tests\Http\Fixtures\HiddenRequest;
use Kinetis\Tests\Http\Fixtures\RegisterAccountRequest;
use Kinetis\Tests\Http\Fixtures\UpdateStatusRequest;
use DateTimeImmutable;
use Kinetis\Reflection\Exception\UnsupportedDefaultValueException;
use Kinetis\Tests\Validation\Fixtures\BoundlessIntFieldRequest;
use Kinetis\Tests\Validation\Fixtures\CallableFieldRequest;
use Kinetis\Tests\Validation\Fixtures\FalseTypedFieldRequest;
use Kinetis\Tests\Validation\Fixtures\FieldlessNestedRequest;
use Kinetis\Tests\Validation\Fixtures\IntersectionTypedFieldRequest;
use Kinetis\Tests\Validation\Fixtures\IterableFieldRequest;
use Kinetis\Tests\Validation\Fixtures\ListOfAnInterfaceRequest;
use Kinetis\Tests\Validation\Fixtures\ListOfOnAStringRequest;
use Kinetis\Tests\Validation\Fixtures\EnumDefaultRequest;
use Kinetis\Tests\Validation\Fixtures\MutuallyRecursiveParent;
use Kinetis\Tests\Validation\Fixtures\NestedObjectDefaultRequest;
use Kinetis\Tests\Validation\Fixtures\NoConstructorFixture;
use Kinetis\Tests\Validation\Fixtures\NullTypedFieldRequest;
use Kinetis\Tests\Validation\Fixtures\ObjectDefaultRequest;
use Kinetis\Tests\Validation\Fixtures\ObjectFieldRequest;
use Kinetis\Tests\Validation\Fixtures\OrderItem;
use Kinetis\Tests\Validation\Fixtures\PlainArrayFieldRequest;
use Kinetis\Tests\Validation\Fixtures\OrderWithItems;
use Kinetis\Tests\Validation\Fixtures\SelfReferencingListRequest;
use Kinetis\Tests\Validation\Fixtures\SelfReferencingRequest;
use Kinetis\Tests\Validation\Fixtures\SortDirection;
use Kinetis\Tests\Validation\Fixtures\TrueTypedFieldRequest;
use Kinetis\Tests\Validation\Fixtures\UnionTypedFieldRequest;
use Kinetis\Validation\Exception\UnsupportedDtoDefinitionException;
use Kinetis\Validation\Exception\UnsupportedScalarTypeException;
use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\Hydrator;
use Kinetis\Validation\JsonObject;
use Kinetis\Validation\JsonTree;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;
use ReflectionParameter;

final class HydratorTest extends TestCase
{
    public function test_hydrates_a_dto_from_valid_data(): void
    {
        $dto = Hydrator::hydrate(CreateUserRequest::class, ['name' => 'Alon', 'email' => 'alon@example.com']);

        self::assertSame('Alon', $dto->name);
        self::assertSame('alon@example.com', $dto->email);
    }

    public function test_hydrates_a_dto_with_an_asymmetric_visibility_property(): void
    {
        $dto = Hydrator::hydrate(UpdateStatusRequest::class, ['status' => 'active']);

        self::assertSame('active', $dto->status);
    }

    public function test_validates_an_asymmetric_visibility_property_the_same_as_any_other(): void
    {
        try {
            Hydrator::hydrate(UpdateStatusRequest::class, ['status' => 'a']);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('status', $e->errors);
        }
    }

    public function test_rejects_an_invalid_email(): void
    {
        try {
            Hydrator::hydrate(CreateUserRequest::class, ['name' => 'Alon', 'email' => 'not-an-email']);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('email', $e->errors);
            self::assertArrayNotHasKey('name', $e->errors);
        }
    }

    public function test_rejects_a_name_shorter_than_the_minimum_length(): void
    {
        try {
            Hydrator::hydrate(CreateUserRequest::class, ['name' => 'Al', 'email' => 'alon@example.com']);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('name', $e->errors);
        }
    }

    public function test_missing_required_field_is_reported(): void
    {
        try {
            Hydrator::hydrate(CreateUserRequest::class, ['name' => 'Alon']);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('email', $e->errors);
        }
    }

    public function test_all_field_errors_are_reported_together(): void
    {
        try {
            Hydrator::hydrate(CreateUserRequest::class, ['name' => 'Al', 'email' => 'not-an-email']);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertCount(2, $e->errors);
        }
    }

    public function test_hydrates_a_dto_with_greater_than_and_regex_constraints(): void
    {
        $dto = Hydrator::hydrate(CreateProductRequest::class, ['sku' => 'ABC123', 'price' => 9.99]);

        self::assertSame('ABC123', $dto->sku);
        self::assertSame(9.99, $dto->price);
    }

    public function test_rejects_a_price_that_is_not_greater_than_zero(): void
    {
        try {
            Hydrator::hydrate(CreateProductRequest::class, ['sku' => 'ABC123', 'price' => 0]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('price', $e->errors);
        }
    }

    public function test_rejects_a_sku_that_does_not_match_the_pattern(): void
    {
        try {
            Hydrator::hydrate(CreateProductRequest::class, ['sku' => 'not-a-sku', 'price' => 9.99]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('sku', $e->errors);
        }
    }

    private function validAccountData(): array
    {
        return [
            'username' => 'alon',
            'bio' => 'short bio',
            'age' => 30,
            'role' => 'admin',
            'website' => 'https://kinetis.dev',
            'referralId' => '550e8400-e29b-41d4-a716-446655440000',
        ];
    }

    public function test_hydrates_a_dto_with_not_blank_max_length_less_than_in_url_and_uuid_constraints(): void
    {
        $dto = Hydrator::hydrate(RegisterAccountRequest::class, $this->validAccountData());

        self::assertSame('alon', $dto->username);
        self::assertSame('admin', $dto->role);
    }

    public function test_rejects_a_blank_username(): void
    {
        try {
            Hydrator::hydrate(RegisterAccountRequest::class, [...$this->validAccountData(), 'username' => '   ']);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('username', $e->errors);
        }
    }

    public function test_rejects_a_bio_longer_than_the_maximum_length(): void
    {
        try {
            Hydrator::hydrate(RegisterAccountRequest::class, [...$this->validAccountData(), 'bio' => str_repeat('x', 21)]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('bio', $e->errors);
        }
    }

    public function test_rejects_an_age_that_is_not_less_than_the_maximum(): void
    {
        try {
            Hydrator::hydrate(RegisterAccountRequest::class, [...$this->validAccountData(), 'age' => 120]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('age', $e->errors);
        }
    }

    public function test_rejects_a_role_outside_the_allowed_choices(): void
    {
        try {
            Hydrator::hydrate(RegisterAccountRequest::class, [...$this->validAccountData(), 'role' => 'superadmin']);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('role', $e->errors);
        }
    }

    public function test_rejects_a_website_that_is_not_a_valid_url(): void
    {
        try {
            Hydrator::hydrate(RegisterAccountRequest::class, [...$this->validAccountData(), 'website' => 'not a url']);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('website', $e->errors);
        }
    }

    public function test_rejects_a_referral_id_that_is_not_a_valid_uuid(): void
    {
        try {
            Hydrator::hydrate(RegisterAccountRequest::class, [...$this->validAccountData(), 'referralId' => 'not-a-uuid']);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('referralId', $e->errors);
        }
    }

    public function test_compile_plan_captures_constraint_arguments_as_plain_data_not_instances(): void
    {
        $plan = Hydrator::compilePlan(CreateUserRequest::class);

        $nameConstraints = $plan['parameters'][0]['constraints'];
        self::assertSame('Kinetis\Validation\Constraints\MinLength', $nameConstraints[0]['class']);
        self::assertSame([3], $nameConstraints[0]['args']);

        $emailConstraints = $plan['parameters'][1]['constraints'];
        self::assertSame('Kinetis\Validation\Constraints\Email', $emailConstraints[0]['class']);
        self::assertSame([], $emailConstraints[0]['args']);
    }

    public function test_hydrating_from_a_compiled_plan_matches_the_live_path_on_success(): void
    {
        $plan = Hydrator::compilePlan(CreateUserRequest::class);
        $dto = Hydrator::hydrate(CreateUserRequest::class, ['name' => 'Alon', 'email' => 'alon@example.com'], $plan);

        self::assertSame('Alon', $dto->name);
        self::assertSame('alon@example.com', $dto->email);
    }

    public function test_hydrating_from_a_compiled_plan_matches_the_live_path_on_every_failure_mode(): void
    {
        $plan = Hydrator::compilePlan(CreateUserRequest::class);

        try {
            Hydrator::hydrate(CreateUserRequest::class, ['name' => 'Al', 'email' => 'not-an-email'], $plan);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertCount(2, $e->errors);
            self::assertArrayHasKey('name', $e->errors);
            self::assertArrayHasKey('email', $e->errors);
        }
    }

    public function test_hydrating_from_a_compiled_plan_reports_a_missing_required_field(): void
    {
        $plan = Hydrator::compilePlan(CreateUserRequest::class);

        try {
            Hydrator::hydrate(CreateUserRequest::class, ['name' => 'Alon'], $plan);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('email', $e->errors);
        }
    }

    public function test_hydrating_from_a_compiled_plan_handles_greater_than_and_regex_constraints(): void
    {
        $plan = Hydrator::compilePlan(CreateProductRequest::class);

        $dto = Hydrator::hydrate(CreateProductRequest::class, ['sku' => 'ABC123', 'price' => 9.99], $plan);
        self::assertSame('ABC123', $dto->sku);

        try {
            Hydrator::hydrate(CreateProductRequest::class, ['sku' => 'ABC123', 'price' => 0], $plan);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('price', $e->errors);
        }
    }

    public function test_compile_plan_for_a_class_with_no_constructor_reports_no_constructor(): void
    {
        $plan = Hydrator::compilePlan(\Kinetis\Tests\Validation\Fixtures\NoConstructorFixture::class);

        self::assertFalse($plan['hasConstructor']);
        self::assertSame([], $plan['parameters']);
    }

    public function test_hydrates_a_nested_dto_from_a_nested_array(): void
    {
        $dto = Hydrator::hydrate(CreateOrderRequest::class, [
            'customerName' => 'Alon',
            'shippingAddress' => ['street' => '1 Infinite Loop', 'city' => 'Cupertino'],
        ]);

        self::assertSame('Alon', $dto->customerName);
        self::assertSame('1 Infinite Loop', $dto->shippingAddress->street);
        self::assertSame('Cupertino', $dto->shippingAddress->city);
    }

    public function test_a_nested_dtos_validation_errors_surface_under_a_dotted_key(): void
    {
        try {
            Hydrator::hydrate(CreateOrderRequest::class, [
                'customerName' => 'Alon',
                'shippingAddress' => ['street' => 'x', 'city' => 'Cupertino'],
            ]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('shippingAddress.street', $e->errors);
            self::assertArrayNotHasKey('customerName', $e->errors);
        }
    }

    public function test_a_missing_nested_required_field_is_reported_under_a_dotted_key(): void
    {
        try {
            Hydrator::hydrate(CreateOrderRequest::class, [
                'customerName' => 'Alon',
                'shippingAddress' => ['street' => '1 Infinite Loop'],
            ]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('shippingAddress.city', $e->errors);
        }
    }

    public function test_top_level_and_nested_errors_are_reported_together(): void
    {
        try {
            Hydrator::hydrate(CreateOrderRequest::class, [
                'customerName' => 'A',
                'shippingAddress' => ['street' => 'x', 'city' => 'Cupertino'],
            ]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('customerName', $e->errors);
            self::assertArrayHasKey('shippingAddress.street', $e->errors);
        }
    }

    public function test_an_already_built_instance_for_a_class_typed_field_is_accepted(): void
    {
        // Mirrors what Dispatcher does for a multipart UploadedFileInterface
        // field merged directly into the data array as an object, never an
        // array — nested hydration must not attempt to touch it.
        $address = new \Kinetis\Tests\Http\Fixtures\Address(street: '1 Infinite Loop', city: 'Cupertino');
        $dto = Hydrator::hydrate(CreateOrderRequest::class, [
            'customerName' => 'Alon',
            'shippingAddress' => $address,
        ]);

        self::assertSame($address, $dto->shippingAddress);
    }

    public function test_compile_plan_embeds_a_nested_plan_for_a_class_typed_parameter(): void
    {
        $plan = Hydrator::compilePlan(CreateOrderRequest::class);

        $addressParam = $plan['parameters'][1];
        self::assertSame('shippingAddress', $addressParam['name']);
        self::assertSame(\Kinetis\Tests\Http\Fixtures\Address::class, $addressParam['dtoClass']);
        self::assertNotNull($addressParam['nestedPlan']);
        self::assertSame(\Kinetis\Tests\Http\Fixtures\Address::class, $addressParam['nestedPlan']['className']);
    }

    public function test_hydrating_a_nested_dto_from_a_compiled_plan_matches_the_live_path(): void
    {
        $plan = Hydrator::compilePlan(CreateOrderRequest::class);
        $dto = Hydrator::hydrate(CreateOrderRequest::class, [
            'customerName' => 'Alon',
            'shippingAddress' => ['street' => '1 Infinite Loop', 'city' => 'Cupertino'],
        ], $plan);

        self::assertSame('1 Infinite Loop', $dto->shippingAddress->street);
    }

    public function test_hydrates_a_list_of_nested_dtos_from_a_list_of_arrays(): void
    {
        $dto = Hydrator::hydrate(OrderWithItems::class, [
            'customerName' => 'Alon',
            'items' => [
                ['product' => 'Widget', 'quantity' => 2],
                ['product' => 'Gadget', 'quantity' => 5],
            ],
        ]);

        self::assertCount(2, $dto->items);
        self::assertInstanceOf(OrderItem::class, $dto->items[0]);
        self::assertSame('Widget', $dto->items[0]->product);
        self::assertSame(2, $dto->items[0]->quantity);
        self::assertSame('Gadget', $dto->items[1]->product);
        self::assertSame(5, $dto->items[1]->quantity);
    }

    public function test_an_empty_list_hydrates_to_an_empty_list(): void
    {
        $dto = Hydrator::hydrate(OrderWithItems::class, ['customerName' => 'Alon', 'items' => []]);

        self::assertSame([], $dto->items);
    }

    public function test_a_list_items_validation_errors_surface_under_a_dotted_index_key(): void
    {
        try {
            Hydrator::hydrate(OrderWithItems::class, [
                'customerName' => 'Alon',
                'items' => [
                    ['product' => 'Widget', 'quantity' => 2],
                    ['product' => 'Gadget', 'quantity' => 0],
                ],
            ]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('items.1.quantity', $e->errors);
            self::assertArrayNotHasKey('items.0.quantity', $e->errors);
        }
    }

    public function test_top_level_and_list_item_errors_are_reported_together(): void
    {
        try {
            Hydrator::hydrate(OrderWithItems::class, [
                'customerName' => 'A',
                'items' => [['product' => 'Widget', 'quantity' => 0]],
            ]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('customerName', $e->errors);
            self::assertArrayHasKey('items.0.quantity', $e->errors);
        }
    }

    public function test_an_already_built_item_instance_is_accepted_as_a_list_element(): void
    {
        $alreadyBuilt = new OrderItem(product: 'Widget', quantity: 2);
        $dto = Hydrator::hydrate(OrderWithItems::class, [
            'customerName' => 'Alon',
            'items' => [$alreadyBuilt, ['product' => 'Gadget', 'quantity' => 5]],
        ]);

        self::assertSame($alreadyBuilt, $dto->items[0]);
        self::assertInstanceOf(OrderItem::class, $dto->items[1]);
    }

    public function test_an_already_built_item_instance_is_accepted_through_a_compiled_plan(): void
    {
        $plan = Hydrator::compilePlan(OrderWithItems::class);
        $alreadyBuilt = new OrderItem(product: 'Widget', quantity: 2);
        $dto = Hydrator::hydrate(OrderWithItems::class, [
            'customerName' => 'Alon',
            'items' => [$alreadyBuilt],
        ], $plan);

        self::assertSame($alreadyBuilt, $dto->items[0]);
    }

    public function test_compile_plan_embeds_a_list_item_plan_for_a_list_of_parameter(): void
    {
        $plan = Hydrator::compilePlan(OrderWithItems::class);

        $itemsParam = $plan['parameters'][1];
        self::assertSame('items', $itemsParam['name']);
        self::assertSame(OrderItem::class, $itemsParam['listItemClass']);
        self::assertNotNull($itemsParam['listItemPlan']);
        self::assertSame(OrderItem::class, $itemsParam['listItemPlan']['className']);
    }

    public function test_hydrating_a_list_from_a_compiled_plan_matches_the_live_path(): void
    {
        $plan = Hydrator::compilePlan(OrderWithItems::class);
        $dto = Hydrator::hydrate(OrderWithItems::class, [
            'customerName' => 'Alon',
            'items' => [['product' => 'Widget', 'quantity' => 2]],
        ], $plan);

        self::assertSame('Widget', $dto->items[0]->product);
    }

    // --- A JSON array/object must not be silently coerced into a scalar
    // that then happens to pass an unrelated constraint. ---

    public function test_an_array_of_strings_for_a_string_field_is_rejected_not_coerced(): void
    {
        try {
            Hydrator::hydrate(CreateUserRequest::class, ['name' => ['aaa', 'bbb'], 'email' => 'a@b.com']);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('name', $e->errors);
        }
    }

    public function test_an_empty_array_for_a_string_field_is_rejected_not_coerced_to_the_string_array(): void
    {
        // The exact reported bypass: (string) [] === "Array", 5 characters,
        // which would otherwise pass #[MinLength(3)] despite being empty.
        try {
            Hydrator::hydrate(CreateUserRequest::class, ['name' => [], 'email' => 'a@b.com']);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('name', $e->errors);
        }
    }

    public function test_an_associative_array_for_a_string_field_is_rejected(): void
    {
        try {
            Hydrator::hydrate(CreateUserRequest::class, ['name' => ['x' => 1], 'email' => 'a@b.com']);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('name', $e->errors);
        }
    }

    public function test_a_non_numeric_string_for_an_int_field_is_rejected_not_coerced_to_zero(): void
    {
        try {
            Hydrator::hydrate(RegisterAccountRequest::class, [...$this->validAccountData(), 'age' => 'not-a-number']);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('age', $e->errors);
        }
    }

    public function test_an_array_for_an_int_field_is_rejected_not_coerced_to_one(): void
    {
        try {
            Hydrator::hydrate(RegisterAccountRequest::class, [...$this->validAccountData(), 'age' => [1, 2, 3]]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('age', $e->errors);
        }
    }

    public function test_a_numeric_string_for_an_int_field_is_accepted(): void
    {
        $dto = Hydrator::hydrate(RegisterAccountRequest::class, [...$this->validAccountData(), 'age' => '42']);

        self::assertSame(42, $dto->age);
    }

    public function test_a_non_boolean_looking_value_for_a_bool_field_is_rejected(): void
    {
        try {
            Hydrator::hydrate(HiddenRequest::class, ['ok' => 'yes']);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('ok', $e->errors);
        }
    }

    public function test_the_exact_boolean_allow_list_is_still_accepted(): void
    {
        foreach ([true, false, 0, 1, '0', '1'] as $value) {
            $dto = Hydrator::hydrate(HiddenRequest::class, ['ok' => $value]);
            self::assertIsBool($dto->ok);
        }
    }

    // --- A nested-DTO field given a non-array value is a 422, not an
    // uncaught TypeError. ---

    public function test_a_scalar_value_for_a_nested_dto_field_is_rejected_not_a_type_error(): void
    {
        try {
            Hydrator::hydrate(CreateOrderRequest::class, [
                'customerName' => 'Alon',
                'shippingAddress' => 'hello',
            ]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('shippingAddress', $e->errors);
        }
    }

    public function test_a_scalar_value_for_a_listof_field_is_rejected_not_a_type_error(): void
    {
        try {
            Hydrator::hydrate(OrderWithItems::class, [
                'customerName' => 'Alon',
                'items' => 'hello',
            ]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('items', $e->errors);
        }
    }

    /**
     * #[ListOf]'s own JSON Schema claims `{type: 'array', items: ...}`
     * exactly like a plain array field's — the same map-shaped-JSON-object
     * bypass applies here too, not just to a bare array/iterable field.
     */
    public function test_a_map_shaped_value_for_a_listof_field_is_rejected_not_silently_iterated(): void
    {
        try {
            Hydrator::hydrate(OrderWithItems::class, [
                'customerName' => 'Alon',
                'items' => ['key' => ['product' => 'widget', 'quantity' => 1]],
            ]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['items' => ['must be a JSON array, not a JSON object.']], $e->errors);
        }
    }

    public function test_an_explicit_null_for_a_non_nullable_field_is_a_validation_error_not_a_type_error(): void
    {
        try {
            Hydrator::hydrate(CreateNoteRequest::class, ['title' => null, 'subtitle' => null]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['title' => ['must not be null.']], $e->errors);
        }
    }

    public function test_an_explicit_null_for_a_nullable_field_hydrates_as_null(): void
    {
        $dto = Hydrator::hydrate(CreateNoteRequest::class, ['title' => 'hello', 'subtitle' => null]);

        self::assertSame('hello', $dto->title);
        self::assertNull($dto->subtitle);
    }

    public function test_the_explicit_null_check_applies_identically_through_a_compiled_plan(): void
    {
        $plan = Hydrator::compilePlan(CreateNoteRequest::class);

        try {
            Hydrator::hydrate(CreateNoteRequest::class, ['title' => null, 'subtitle' => null], $plan);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['title' => ['must not be null.']], $e->errors);
        }
    }

    /**
     * KINETIS-76: a plain `array` field (no #[ListOf]) previously reached
     * `new $className(...)` unchecked for a non-array value, surfacing as
     * a raw TypeError instead of the same 422/validation-error contract
     * every other builtin type already gets.
     */
    public function test_a_non_array_value_for_a_plain_array_field_is_a_validation_error_not_a_type_error(): void
    {
        try {
            Hydrator::hydrate(PlainArrayFieldRequest::class, ['tags' => 'not-an-array']);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['tags' => ['must be an array, value given.']], $e->errors);
        }
    }

    public function test_a_real_array_value_for_a_plain_array_field_hydrates_normally(): void
    {
        $instance = Hydrator::hydrate(PlainArrayFieldRequest::class, ['tags' => ['a', 'b']]);

        self::assertSame(['a', 'b'], $instance->tags);
    }

    /**
     * A plain `array` field's own JSON Schema claims `{type: 'array'}` —
     * a real JSON *array*, not any array-shaped PHP value. This
     * particular call is a *direct* Hydrator::hydrate() call with a
     * hand-built PHP map — never JSON-decoded through Dispatcher/
     * McpServer's own JsonTree pipeline at all, so there is no
     * JsonObject marking involved here — the same map-shaped PHP array a
     * `json_decode(..., associative: true)` call, or a form-decoded
     * body, would also produce for a genuine JSON object. See
     * JsonTreeTest for how a real request's own JSON object is
     * distinguished from a real JSON array before either ever reaches
     * this class, provenance-preserving even when its own keys happen
     * to look sequential.
     */
    public function test_a_map_shaped_value_for_a_plain_array_field_is_a_validation_error(): void
    {
        try {
            Hydrator::hydrate(PlainArrayFieldRequest::class, ['tags' => ['key' => 'value']]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['tags' => ['must be a JSON array, not a JSON object.']], $e->errors);
        }
    }

    public function test_an_empty_array_value_for_a_plain_array_field_hydrates_normally(): void
    {
        // A hand-built, empty PHP array passed directly to hydrate() —
        // not a real JSON-decoded value at all, so there is nothing
        // ambiguous about it: a genuinely empty JSON *object* reaching
        // this same field through the real HTTP/MCP decode pipeline is
        // rejected instead, proven directly by
        // JsonTreeTest::test_an_empty_json_object_becomes_a_marker_wrapping_an_empty_array()
        // and DispatcherTest::test_an_empty_json_object_is_still_rejected_for_a_plain_array_field().
        $instance = Hydrator::hydrate(PlainArrayFieldRequest::class, ['tags' => []]);

        self::assertSame([], $instance->tags);
    }

    // KINETIS-76 follow-up: the complete, audited policy for every one of
    // the twelve builtin type names PHP can attach to a parameter (see
    // JsonSchema::forType()'s own docblock for how this list was derived).
    // typeMismatchMessage() is the one boundary shared by #[Body] fields
    // here, #[Query]/path parameters via Dispatcher, and MCP tool
    // arguments via McpDispatcher — proving it here proves it everywhere.

    public function test_iterable_gets_the_identical_array_check_as_plain_array(): void
    {
        try {
            Hydrator::hydrate(IterableFieldRequest::class, ['items' => 'not-an-array']);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['items' => ['must be an array, value given.']], $e->errors);
        }
    }

    public function test_a_real_array_value_for_an_iterable_field_hydrates_normally(): void
    {
        $instance = Hydrator::hydrate(IterableFieldRequest::class, ['items' => ['a', 'b']]);

        self::assertSame(['a', 'b'], $instance->items);
    }

    public function test_a_map_shaped_value_for_an_iterable_field_is_a_validation_error(): void
    {
        try {
            Hydrator::hydrate(IterableFieldRequest::class, ['items' => ['key' => 'value']]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['items' => ['must be a JSON array, not a JSON object.']], $e->errors);
        }
    }

    public function test_a_non_null_value_for_a_standalone_null_typed_field_is_a_validation_error(): void
    {
        try {
            Hydrator::hydrate(NullTypedFieldRequest::class, ['marker' => 'not-null']);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['marker' => ['must be null, value given.']], $e->errors);
        }
    }

    public function test_an_explicit_null_value_for_a_standalone_null_typed_field_hydrates_normally(): void
    {
        $instance = Hydrator::hydrate(NullTypedFieldRequest::class, ['marker' => null]);

        self::assertNull($instance->marker);
    }

    public function test_a_non_true_value_for_a_standalone_true_typed_field_is_a_validation_error(): void
    {
        try {
            Hydrator::hydrate(TrueTypedFieldRequest::class, ['confirmed' => false]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['confirmed' => ['must be true, boolean given.']], $e->errors);
        }
    }

    public function test_the_literal_true_value_for_a_standalone_true_typed_field_hydrates_normally(): void
    {
        $instance = Hydrator::hydrate(TrueTypedFieldRequest::class, ['confirmed' => true]);

        self::assertTrue($instance->confirmed);
    }

    public function test_a_non_false_value_for_a_standalone_false_typed_field_is_a_validation_error(): void
    {
        try {
            Hydrator::hydrate(FalseTypedFieldRequest::class, ['declined' => true]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['declined' => ['must be false, boolean given.']], $e->errors);
        }
    }

    public function test_the_literal_false_value_for_a_standalone_false_typed_field_hydrates_normally(): void
    {
        $instance = Hydrator::hydrate(FalseTypedFieldRequest::class, ['declined' => false]);

        self::assertFalse($instance->declined);
    }

    /**
     * `object` has no truthful JSON representation this framework
     * accepts — a decoded JSON body only ever produces arrays/scalars,
     * never a real PHP object — so any real value supplied for it is
     * rejected outright rather than reaching `new $className(...)`
     * unchecked and surfacing as a raw TypeError.
     */
    public function test_any_value_for_an_object_typed_field_is_a_validation_error(): void
    {
        try {
            Hydrator::hydrate(ObjectFieldRequest::class, ['extra' => ['a' => 1]]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(
                ['extra' => ['cannot be provided through JSON input — no request value can construct a plain object.']],
                $e->errors,
            );
        }
    }

    public function test_an_omitted_object_typed_field_with_no_default_is_reported_as_required_not_rejected(): void
    {
        try {
            Hydrator::hydrate(ObjectFieldRequest::class, []);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['extra' => ['is required.']], $e->errors);
        }
    }

    /**
     * `callable` is rejected unconditionally, not just because it has no
     * truthful JSON shape but because it's a real security boundary: a
     * JSON string reaching a callable-typed parameter is exactly the
     * shape of an arbitrary-function-name-injection risk if the
     * constructor ever invokes it. `"strtoupper"` is a genuinely valid
     * PHP callable — proving this is rejected regardless of whether the
     * attacker-supplied string happens to name something harmless.
     */
    public function test_any_value_for_a_callable_typed_field_is_a_validation_error(): void
    {
        try {
            Hydrator::hydrate(CallableFieldRequest::class, ['handler' => 'strtoupper']);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(
                ['handler' => ['cannot be provided through JSON input — callable values are not accepted.']],
                $e->errors,
            );
        }
    }

    public function test_an_omitted_callable_typed_field_with_no_default_is_reported_as_required_not_rejected(): void
    {
        try {
            Hydrator::hydrate(CallableFieldRequest::class, []);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['handler' => ['is required.']], $e->errors);
        }
    }

    /**
     * Every one of the twelve real builtin type names has its own arm in
     * typeMismatchMessage() now (see the class docblock) — a genuinely
     * unrecognized scalarType string can only reach the fail-closed
     * default arm, which throws rather than silently returning null
     * (accept-anything). This is the exact fail-open pattern that let
     * object/callable/iterable/null/true/false all reach a raw
     * constructor unchecked before this class's own audit gave each of
     * them a real policy; a future/unknown type must not get the same
     * silent treatment.
     */
    public function test_a_genuinely_unrecognized_scalar_type_fails_closed_not_open(): void
    {
        $this->expectException(UnsupportedScalarTypeException::class);
        $this->expectExceptionMessage('not-a-real-builtin-type');

        Hydrator::typeMismatchMessage('not-a-real-builtin-type', 'some value');
    }

    public function test_mixed_has_its_own_explicit_arm_and_accepts_any_non_null_value(): void
    {
        self::assertNull(Hydrator::typeMismatchMessage('mixed', 'anything'));
        self::assertNull(Hydrator::typeMismatchMessage('mixed', 42));
        self::assertNull(Hydrator::typeMismatchMessage('mixed', ['a', 'b']));
        self::assertNull(Hydrator::typeMismatchMessage('mixed', true));
    }

    // --- A class-typed field and a #[ListOf] element accept exactly two
    // shapes: an object-shaped value hydrated into the declared class, or
    // a value already an instance of it. ---

    public function test_an_object_of_another_class_for_a_nested_dto_field_is_rejected(): void
    {
        try {
            Hydrator::hydrate(CreateOrderRequest::class, [
                'customerName' => 'Alon',
                'shippingAddress' => new OrderItem(product: 'Widget', quantity: 2),
            ]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(
                ['shippingAddress' => ['must be a ' . Address::class . ' instance.']],
                $e->errors,
            );
        }
    }

    public function test_a_scalar_list_element_is_rejected_under_its_own_dotted_index_key(): void
    {
        try {
            Hydrator::hydrate(OrderWithItems::class, [
                'customerName' => 'Alon',
                'items' => [['product' => 'Widget', 'quantity' => 2], 'nope'],
            ]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['items.1' => ['must be an object, value given.']], $e->errors);
        }
    }

    public function test_a_null_list_element_is_rejected(): void
    {
        try {
            Hydrator::hydrate(OrderWithItems::class, ['customerName' => 'Alon', 'items' => [null]]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['items.0' => ['must be an object, null given.']], $e->errors);
        }
    }

    public function test_an_object_of_another_class_as_a_list_element_is_rejected(): void
    {
        try {
            Hydrator::hydrate(OrderWithItems::class, [
                'customerName' => 'Alon',
                'items' => [new Address(street: '1 Infinite Loop', city: 'Cupertino')],
            ]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['items.0' => ['must be a ' . OrderItem::class . ' instance.']], $e->errors);
        }
    }

    public function test_a_wrong_shaped_list_element_is_rejected_identically_through_a_compiled_plan(): void
    {
        $plan = Hydrator::compilePlan(OrderWithItems::class);

        try {
            Hydrator::hydrate(OrderWithItems::class, ['customerName' => 'Alon', 'items' => [42]], $plan);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['items.0' => ['must be an object, integer given.']], $e->errors);
        }
    }

    /**
     * A real JSON object element, marked by JsonTree::convert() the way a
     * decoded request body's own elements are, hydrates exactly like the
     * plain associative array a direct hydrate() call supplies.
     */
    public function test_a_marked_json_object_list_element_hydrates_into_the_item_class(): void
    {
        $decoded = json_decode('{"customerName": "Alon", "items": [{"product": "Widget", "quantity": 2}]}', associative: false);
        $converted = JsonTree::convert($decoded);
        self::assertInstanceOf(JsonObject::class, $converted);

        $dto = Hydrator::hydrate(OrderWithItems::class, $converted->toArray());

        self::assertInstanceOf(OrderItem::class, $dto->items[0]);
        self::assertSame('Widget', $dto->items[0]->product);
    }

    /**
     * The fields of a request body decoded exactly the way Dispatcher
     * decodes one: every JSON object marked, every JSON array left plain.
     *
     * @return array<string, mixed>
     */
    private static function decodedBody(string $json): array
    {
        $converted = JsonTree::convert(json_decode($json, associative: false));
        self::assertInstanceOf(JsonObject::class, $converted);

        return $converted->toArray();
    }

    public function test_a_json_array_for_a_nested_dto_field_is_rejected(): void
    {
        try {
            Hydrator::hydrate(CreateOrderRequest::class, self::decodedBody(
                '{"customerName": "Alon", "shippingAddress": ["1 Infinite Loop", "Cupertino"]}',
            ));
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(
                ['shippingAddress' => ['must be a JSON object, not a JSON array.']],
                $e->errors,
            );
        }
    }

    public function test_a_json_array_for_a_nested_dto_field_is_rejected_through_a_compiled_plan(): void
    {
        $plan = Hydrator::compilePlan(CreateOrderRequest::class);

        try {
            Hydrator::hydrate(
                CreateOrderRequest::class,
                self::decodedBody('{"customerName": "Alon", "shippingAddress": []}'),
                $plan,
            );
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(
                ['shippingAddress' => ['must be a JSON object, not a JSON array.']],
                $e->errors,
            );
        }
    }

    /**
     * A class hydrating from no fields at all is the case an empty JSON
     * array would otherwise slip through: `[]` carries nothing the plan
     * would miss, so only the object/array distinction rejects it.
     */
    public function test_an_empty_json_array_is_rejected_for_a_field_typed_as_a_fieldless_class(): void
    {
        try {
            Hydrator::hydrate(FieldlessNestedRequest::class, self::decodedBody('{"settings": [], "extras": []}'));
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(
                ['settings' => ['must be a JSON object, not a JSON array.']],
                $e->errors,
            );
        }
    }

    public function test_an_empty_json_array_is_rejected_for_a_fieldless_list_element(): void
    {
        try {
            Hydrator::hydrate(FieldlessNestedRequest::class, self::decodedBody('{"settings": {}, "extras": [[]]}'));
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(
                ['extras.0' => ['must be a JSON object, not a JSON array.']],
                $e->errors,
            );
        }
    }

    public function test_an_empty_json_array_is_rejected_for_a_fieldless_list_element_through_a_compiled_plan(): void
    {
        $plan = Hydrator::compilePlan(FieldlessNestedRequest::class);

        try {
            Hydrator::hydrate(
                FieldlessNestedRequest::class,
                self::decodedBody('{"settings": {}, "extras": [{}, ["a"]]}'),
                $plan,
            );
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(
                ['extras.1' => ['must be a JSON object, not a JSON array.']],
                $e->errors,
            );
        }
    }

    public function test_a_marked_json_object_hydrates_a_fieldless_nested_field_and_list(): void
    {
        $dto = Hydrator::hydrate(FieldlessNestedRequest::class, self::decodedBody('{"settings": {}, "extras": [{}, {}]}'));

        self::assertInstanceOf(NoConstructorFixture::class, $dto->settings);
        self::assertCount(2, $dto->extras);
    }

    /**
     * The direct/form call path carries no JSON object/array distinction
     * of its own, so a map-shaped PHP array is the object spelling there.
     */
    public function test_a_map_shaped_array_still_hydrates_a_nested_dto_field_on_the_direct_call_path(): void
    {
        $dto = Hydrator::hydrate(CreateOrderRequest::class, [
            'customerName' => 'Alon',
            'shippingAddress' => ['street' => '1 Infinite Loop', 'city' => 'Cupertino'],
        ]);

        self::assertInstanceOf(Address::class, $dto->shippingAddress);
        self::assertSame('Cupertino', $dto->shippingAddress->city);
    }

    // --- A field typed as a class that cannot be instantiated accepts an
    // existing instance and nothing else — the UploadedFileInterface a
    // multipart request carries. ---

    public function test_compile_plan_records_a_non_instantiable_field_with_no_nested_plan(): void
    {
        $plan = Hydrator::compilePlan(AvatarUploadRequest::class);

        $avatarParam = $plan['parameters'][1];
        self::assertSame('avatar', $avatarParam['name']);
        self::assertSame(UploadedFileInterface::class, $avatarParam['dtoClass']);
        self::assertNull($avatarParam['nestedPlan']);
    }

    public function test_a_prebuilt_uploaded_file_is_accepted_for_an_interface_typed_field(): void
    {
        $file = new UploadedFile(Stream::create('fake image bytes'), 16, UPLOAD_ERR_OK, 'avatar.png', 'image/png');

        $dto = Hydrator::hydrate(AvatarUploadRequest::class, ['name' => 'Alon', 'avatar' => $file]);

        self::assertSame($file, $dto->avatar);
    }

    public function test_a_prebuilt_uploaded_file_is_accepted_through_a_compiled_plan(): void
    {
        $plan = Hydrator::compilePlan(AvatarUploadRequest::class);
        $file = new UploadedFile(Stream::create('x'), 1, UPLOAD_ERR_OK, 'a.png', 'image/png');

        $dto = Hydrator::hydrate(AvatarUploadRequest::class, ['name' => 'Alon', 'avatar' => $file], $plan);

        self::assertSame($file, $dto->avatar);
    }

    public function test_an_array_for_an_interface_typed_field_is_a_validation_error_not_a_raw_error(): void
    {
        try {
            Hydrator::hydrate(AvatarUploadRequest::class, ['name' => 'Alon', 'avatar' => ['tmp_name' => '/tmp/x']]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(
                ['avatar' => ['must be a ' . UploadedFileInterface::class . ' instance.']],
                $e->errors,
            );
        }
    }

    public function test_a_scalar_for_an_interface_typed_field_is_rejected_through_a_compiled_plan(): void
    {
        $plan = Hydrator::compilePlan(AvatarUploadRequest::class);

        try {
            Hydrator::hydrate(AvatarUploadRequest::class, ['name' => 'Alon', 'avatar' => '/tmp/x'], $plan);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(
                ['avatar' => ['must be a ' . UploadedFileInterface::class . ' instance.']],
                $e->errors,
            );
        }
    }

    // --- `int` accepts a real int, an integral in-range float, and a
    // plain base-10 integer string, and nothing else. ---

    /**
     * @return iterable<string, list<mixed>>
     */
    public static function integerValuedSpellings(): iterable
    {
        yield 'a JSON integer' => [42, 42];
        yield 'a decimal string' => ['42', 42];
        yield 'a sign-prefixed string' => ['+42', 42];
        yield 'a negative string' => ['-42', -42];
        yield 'a float with no fractional part' => [42.0, 42];
        yield 'a negative integer' => [-42, -42];
        yield 'the largest representable int' => [PHP_INT_MAX, PHP_INT_MAX];
        yield 'the smallest representable int' => [PHP_INT_MIN, PHP_INT_MIN];
        yield 'the largest representable int as a string' => [(string) PHP_INT_MAX, PHP_INT_MAX];
    }

    #[DataProvider('integerValuedSpellings')]
    public function test_an_integer_valued_spelling_is_accepted_for_an_int_field(mixed $value, int $expected): void
    {
        $dto = Hydrator::hydrate(BoundlessIntFieldRequest::class, ['count' => $value]);

        self::assertSame($expected, $dto->count);
    }

    /**
     * Every value here is either not an integer at all or not spelled as
     * one. `"1.0000000000000001"` is the case a float step cannot decide:
     * it is the same `double` as `1`, and only reading the string as
     * written keeps it out.
     *
     * @return iterable<string, list<mixed>>
     */
    public static function nonIntegerNumbers(): iterable
    {
        yield 'a fractional float' => [30.5];
        yield 'a fractional string' => ['1.5'];
        yield 'a string a double cannot tell from 1' => ['1.0000000000000001'];
        yield 'a decimal-spelled string' => ['42.0'];
        yield 'an exponent-spelled string' => ['4.2e1'];
        yield 'a whitespace-padded string' => [' 42 '];
        yield 'a float past the integer range' => [1.0e20];
        yield 'a string past the integer range' => ['9223372036854775808'];
        yield 'a non-finite float' => [INF];
        yield 'a non-finite string' => ['1e999'];
        yield 'a non-numeric string' => ['not-a-number'];
    }

    #[DataProvider('nonIntegerNumbers')]
    public function test_a_value_that_is_not_an_integer_is_rejected_not_truncated(mixed $value): void
    {
        try {
            Hydrator::hydrate(BoundlessIntFieldRequest::class, ['count' => $value]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(
                ['count' => ['must be an integer within the platform integer range.']],
                $e->errors,
            );
        }
    }

    #[DataProvider('nonIntegerNumbers')]
    public function test_a_value_that_is_not_an_integer_is_rejected_identically_through_a_compiled_plan(mixed $value): void
    {
        $plan = Hydrator::compilePlan(BoundlessIntFieldRequest::class);

        try {
            Hydrator::hydrate(BoundlessIntFieldRequest::class, ['count' => $value], $plan);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(
                ['count' => ['must be an integer within the platform integer range.']],
                $e->errors,
            );
        }
    }

    public function test_a_non_numeric_value_for_an_int_field_names_the_type_it_was_given(): void
    {
        try {
            Hydrator::hydrate(BoundlessIntFieldRequest::class, ['count' => true]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['count' => ['must be an integer, boolean given.']], $e->errors);
        }
    }

    public function test_a_numeric_string_is_still_accepted_for_a_float_field(): void
    {
        $dto = Hydrator::hydrate(CreateProductRequest::class, ['sku' => 'ABC123', 'price' => '9.99']);

        self::assertSame(9.99, $dto->price);
    }

    /**
     * @return iterable<string, list<mixed>>
     */
    public static function nonFiniteNumbers(): iterable
    {
        yield 'a float that overflowed to INF' => [INF];
        yield 'a string that overflows to INF' => ['1e999'];
        yield 'NAN' => [NAN];
    }

    #[DataProvider('nonFiniteNumbers')]
    public function test_a_non_finite_value_for_a_float_field_is_rejected(mixed $value): void
    {
        try {
            Hydrator::hydrate(CreateProductRequest::class, ['sku' => 'ABC123', 'price' => $value]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['price' => ['must be a finite number.']], $e->errors);
        }
    }

    // --- A DTO definition Hydrator cannot hydrate is rejected when its
    // plan is compiled, not when a request reaches its constructor. ---

    public function test_a_union_typed_constructor_parameter_is_rejected(): void
    {
        $this->expectException(UnsupportedDtoDefinitionException::class);
        $this->expectExceptionMessage('UnionTypedFieldRequest::$identifier');

        Hydrator::compilePlan(UnionTypedFieldRequest::class);
    }

    public function test_an_intersection_typed_constructor_parameter_is_rejected(): void
    {
        $this->expectException(UnsupportedDtoDefinitionException::class);
        $this->expectExceptionMessage('IntersectionTypedFieldRequest::$collection');

        Hydrator::compilePlan(IntersectionTypedFieldRequest::class);
    }

    public function test_the_live_path_rejects_an_unsupported_definition_the_same_way(): void
    {
        $this->expectException(UnsupportedDtoDefinitionException::class);

        Hydrator::hydrate(UnionTypedFieldRequest::class, ['identifier' => 'abc']);
    }

    public function test_a_directly_recursive_dto_definition_is_rejected(): void
    {
        $this->expectException(UnsupportedDtoDefinitionException::class);
        $this->expectExceptionMessage('SelfReferencingRequest::$child');

        Hydrator::compilePlan(SelfReferencingRequest::class);
    }

    public function test_a_directly_recursive_list_of_definition_is_rejected(): void
    {
        $this->expectException(UnsupportedDtoDefinitionException::class);
        $this->expectExceptionMessage('SelfReferencingListRequest::$children');

        Hydrator::compilePlan(SelfReferencingListRequest::class);
    }

    public function test_mutually_recursive_dto_definitions_are_rejected(): void
    {
        $this->expectException(UnsupportedDtoDefinitionException::class);
        $this->expectExceptionMessage('MutuallyRecursiveChild::$parent');

        Hydrator::compilePlan(MutuallyRecursiveParent::class);
    }

    public function test_list_of_on_a_parameter_that_is_not_an_array_is_rejected(): void
    {
        $this->expectException(UnsupportedDtoDefinitionException::class);
        $this->expectExceptionMessage('#[ListOf] only applies to a parameter typed array.');

        Hydrator::compilePlan(ListOfOnAStringRequest::class);
    }

    public function test_list_of_naming_a_class_that_cannot_be_instantiated_is_rejected(): void
    {
        $this->expectException(UnsupportedDtoDefinitionException::class);
        $this->expectExceptionMessage('ListOfAnInterfaceRequest::$files');

        Hydrator::compilePlan(ListOfAnInterfaceRequest::class);
    }

    public function test_a_plan_cannot_be_compiled_for_a_class_that_cannot_be_instantiated(): void
    {
        $this->expectException(UnsupportedDtoDefinitionException::class);
        $this->expectExceptionMessage('it cannot be instantiated');

        Hydrator::compilePlan(UploadedFileInterface::class);
    }

    // --- A default value a plan captures must be the same value on
    // every request that reaches it. ---

    public function test_an_enum_case_default_is_captured_and_used_when_the_field_is_absent(): void
    {
        $plan = Hydrator::compilePlan(EnumDefaultRequest::class);

        self::assertSame(SortDirection::Ascending, $plan['parameters'][1]['defaultValue']);
        self::assertSame(SortDirection::Ascending, Hydrator::hydrate(EnumDefaultRequest::class, ['term' => 'kinetis'])->direction);
    }

    public function test_a_default_that_constructs_an_object_is_rejected_when_the_plan_is_compiled(): void
    {
        $this->expectException(UnsupportedDefaultValueException::class);
        $this->expectExceptionMessage('ObjectDefaultRequest, parameter "$since"');
        $this->expectExceptionMessage('DateTimeImmutable');

        Hydrator::compilePlan(ObjectDefaultRequest::class);
    }

    /**
     * The live path — a first hydrate() with no compiled plan, which is
     * how development runs — refuses the identical declaration the build
     * refuses, at the same point in the same words.
     */
    public function test_the_live_path_rejects_an_object_default_the_same_way(): void
    {
        $this->expectException(UnsupportedDefaultValueException::class);

        Hydrator::hydrate(ObjectDefaultRequest::class, []);
    }

    public function test_an_object_nested_inside_an_array_default_is_rejected(): void
    {
        $this->expectException(UnsupportedDefaultValueException::class);
        $this->expectExceptionMessage('ArrayObject');

        Hydrator::compilePlan(NestedObjectDefaultRequest::class);
    }

    /**
     * What the rejection above prevents, shown against a plan handed in
     * directly: PHP evaluates `new DateTimeImmutable()` afresh every time
     * the parameter goes unfilled, while a plan holding one instance
     * hands that same instance to every hydration for as long as the
     * worker lives.
     */
    public function test_a_captured_object_default_would_be_shared_by_every_hydration(): void
    {
        $parameter = new ReflectionParameter([ObjectDefaultRequest::class, '__construct'], 'since');

        self::assertNotSame($parameter->getDefaultValue(), $parameter->getDefaultValue());

        $capturedPlan = [
            'className' => ObjectDefaultRequest::class,
            'hasConstructor' => true,
            'parameters' => [[
                'name' => 'since',
                'scalarType' => null,
                'dtoClass' => DateTimeImmutable::class,
                'nestedPlan' => null,
                'listItemClass' => null,
                'listItemPlan' => null,
                'hasDefault' => true,
                'defaultValue' => $parameter->getDefaultValue(),
                'allowsNull' => true,
                'constraints' => [],
            ]],
        ];

        self::assertSame(
            Hydrator::hydrate(ObjectDefaultRequest::class, [], $capturedPlan)->since,
            Hydrator::hydrate(ObjectDefaultRequest::class, [], $capturedPlan)->since,
        );
    }
}
