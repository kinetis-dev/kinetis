<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation;

use Kinetis\Tests\Http\Fixtures\CreateOrderRequest;
use Kinetis\Tests\Http\Fixtures\CreateUserRequest;
use Kinetis\Tests\Validation\Fixtures\NoConstructorFixture;
use Kinetis\Tests\Validation\Fixtures\ObjectMapFieldRequest;
use Kinetis\Tests\Validation\Fixtures\OrderWithItems;
use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\Hydrator;
use Kinetis\Validation\InputSource;
use Kinetis\Validation\JsonObject;
use Kinetis\Validation\JsonSchema;
use Kinetis\Validation\JsonTree;
use PHPUnit\Framework\TestCase;

/**
 * JSON and MCP input objects are closed: a member the DTO does not
 * declare fails rather than being discarded. Text and Native stay open,
 * and every generated object schema says so.
 */
final class ClosedInputTest extends TestCase
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

    public function test_an_unknown_top_level_member_is_reported_on_its_own_path(): void
    {
        try {
            Hydrator::hydrate(
                CreateUserRequest::class,
                self::decodedBody('{"name": "Alon", "email": "a@example.com", "nmae": "typo"}'),
                source: InputSource::Json,
            );
            self::fail('Expected an unknown member to be refused.');
        } catch (ValidationException $e) {
            self::assertCount(1, $e->violations);
            self::assertSame(['nmae'], $e->violations[0]->path);
            self::assertSame('unexpected_field', $e->violations[0]->code);
            self::assertSame('is not expected.', $e->violations[0]->message);
        }
    }

    public function test_every_unknown_member_is_reported_in_the_inputs_own_order(): void
    {
        try {
            Hydrator::hydrate(
                CreateUserRequest::class,
                self::decodedBody('{"zeta": 1, "name": "Alon", "alpha": 2, "email": "a@example.com"}'),
                source: InputSource::Json,
            );
            self::fail('Expected both unknown members to be refused.');
        } catch (ValidationException $e) {
            self::assertSame(
                [['zeta'], ['alpha']],
                array_map(static fn ($violation) => $violation->path, $e->violations),
            );
        }
    }

    /**
     * One request, one answer: an unknown member and an ordinary field
     * failure arrive together rather than one per round trip.
     */
    public function test_unknown_members_combine_with_ordinary_field_failures(): void
    {
        try {
            Hydrator::hydrate(
                CreateUserRequest::class,
                self::decodedBody('{"name": "Alon", "extra": true}'),
                source: InputSource::Json,
            );
            self::fail('Expected both failures to be reported.');
        } catch (ValidationException $e) {
            self::assertSame(
                [['email'], ['extra']],
                array_map(static fn ($violation) => $violation->path, $e->violations),
            );
            self::assertSame(['required', 'unexpected_field'], array_map(
                static fn ($violation) => $violation->code,
                $e->violations,
            ));
        }
    }

    public function test_a_nested_dto_is_closed_at_every_level(): void
    {
        try {
            Hydrator::hydrate(
                CreateOrderRequest::class,
                self::decodedBody(
                    '{"customerName": "Alon", "shippingAddress": '
                    . '{"street": "1 Main", "city": "Tel Aviv", "county": "nope"}}',
                ),
                source: InputSource::Json,
            );
            self::fail('Expected the nested unknown member to be refused.');
        } catch (ValidationException $e) {
            self::assertSame(['shippingAddress', 'county'], $e->violations[0]->path);
            self::assertSame('unexpected_field', $e->violations[0]->code);
        }
    }

    public function test_a_list_element_dto_is_closed_under_its_own_index(): void
    {
        try {
            Hydrator::hydrate(
                OrderWithItems::class,
                self::decodedBody(
                    '{"customerName": "Alon", "items": [{"product": "Widget", "quantity": 2, "colour": "red"}]}',
                ),
                source: InputSource::Json,
            );
            self::fail('Expected the unknown element member to be refused.');
        } catch (ValidationException $e) {
            self::assertSame(['items', 0, 'colour'], $e->violations[0]->path);
        }
    }

    /**
     * #[ObjectMap] is an open JSON object inside its own property, which
     * closure does not reach into: the map's keys are its value, while
     * the DTO holding it is closed like any other.
     */
    public function test_an_object_map_property_stays_open_while_its_owner_stays_closed(): void
    {
        $dto = Hydrator::hydrate(
            ObjectMapFieldRequest::class,
            self::decodedBody('{"name": "Alon", "meta": {"anything": 1, "at": "all"}}'),
            source: InputSource::Json,
        );

        self::assertSame(['anything' => 1, 'at' => 'all'], $dto->meta);

        $this->expectException(ValidationException::class);

        Hydrator::hydrate(
            ObjectMapFieldRequest::class,
            self::decodedBody('{"name": "Alon", "meta": {}, "surprise": 1}'),
            source: InputSource::Json,
        );
    }

    /**
     * A form body carries members that are not fields — a CSRF token, the
     * submit button's own name, a honeypot — and a Native caller hands
     * over whole database rows. Neither is a client mistake, so neither
     * is closed.
     */
    public function test_text_and_native_sources_keep_their_unknown_member_tolerance(): void
    {
        $data = ['name' => 'Alon', 'email' => 'a@example.com', '_token' => 'csrf', 'submit' => 'Save'];

        self::assertSame('Alon', Hydrator::hydrate(CreateUserRequest::class, $data, source: InputSource::Text)->name);
        self::assertSame('Alon', Hydrator::hydrate(CreateUserRequest::class, $data, source: InputSource::Native)->name);
    }

    /**
     * A class with no constructor takes no members at all, so under JSON
     * every member it is sent is an unknown one.
     */
    public function test_a_constructorless_dto_is_closed_too(): void
    {
        self::assertInstanceOf(
            NoConstructorFixture::class,
            Hydrator::hydrate(NoConstructorFixture::class, [], source: InputSource::Json),
        );

        try {
            Hydrator::hydrate(NoConstructorFixture::class, ['anything' => 1], source: InputSource::Json);
            self::fail('Expected a member of a memberless DTO to be refused.');
        } catch (ValidationException $e) {
            self::assertSame(['anything'], $e->violations[0]->path);
            self::assertSame('unexpected_field', $e->violations[0]->code);
        }
    }

    /**
     * What the runtime enforces is what the document promises — including
     * nested objects, and including the object that holds an open
     * #[ObjectMap] property.
     */
    public function test_every_generated_object_schema_is_closed(): void
    {
        $order = JsonSchema::forClass(CreateOrderRequest::class);

        self::assertFalse($order['additionalProperties']);
        self::assertFalse($order['properties']['shippingAddress']['additionalProperties']);

        $map = JsonSchema::forClass(ObjectMapFieldRequest::class);

        self::assertFalse($map['additionalProperties']);
        self::assertTrue($map['properties']['meta']['additionalProperties']);
    }
}
