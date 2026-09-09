<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation;

use InvalidArgumentException;
use Kinetis\Validation\Violation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ViolationTest extends TestCase
{
    public function test_it_keeps_every_part_of_the_failure(): void
    {
        $violation = new Violation(['items', 2, 'quantity'], 'greater_than', 'must be greater than 0.', ['bound' => 0]);

        self::assertSame(['items', 2, 'quantity'], $violation->path);
        self::assertSame('greater_than', $violation->code);
        self::assertSame('must be greater than 0.', $violation->message);
        self::assertSame(['bound' => 0], $violation->parameters);
    }

    public function test_an_empty_path_addresses_the_payload_as_a_whole(): void
    {
        self::assertSame([], new Violation([], 'code', 'message.')->path);
    }

    public function test_under_prepends_segments_in_order_without_touching_the_rest(): void
    {
        $violation = new Violation(['quantity'], 'greater_than', 'must be greater than 0.', ['bound' => 0]);

        $nested = $violation->under('items', 2);

        self::assertSame(['items', 2, 'quantity'], $nested->path);
        self::assertSame('greater_than', $nested->code);
        self::assertSame('must be greater than 0.', $nested->message);
        self::assertSame(['bound' => 0], $nested->parameters);
        // The original is untouched: a violation prefixed for one
        // element must not follow the list into the next.
        self::assertSame(['quantity'], $violation->path);
    }

    public function test_repeated_prefixing_nests_from_the_inside_out(): void
    {
        $violation = new Violation(['street'], 'required', 'is required.')
            ->under('shippingAddress')
            ->under('orders', 0);

        self::assertSame(['orders', 0, 'shippingAddress', 'street'], $violation->path);
    }

    /**
     * Both transports encode a violation through this one method, so a
     * change here is a change to the HTTP problem document and the MCP
     * tool-error envelope at once.
     */
    public function test_it_serializes_to_the_shape_both_transports_put_on_the_wire(): void
    {
        $violation = new Violation(['tags', 0], 'type_mismatch', 'must be a string, integer given.', [
            'expected' => 'string',
            'given' => 'integer',
        ]);

        self::assertSame(
            '{"path":["tags",0],"code":"type_mismatch","message":"must be a string, integer given.",'
            . '"parameters":{"expected":"string","given":"integer"}}',
            json_encode($violation, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * `parameters` is a map in both documents, and PHP's empty array
     * would otherwise encode as `[]` — changing the member's JSON type
     * with its contents, which every consumer would have to special-case.
     */
    public function test_an_empty_parameter_set_still_encodes_as_a_json_object(): void
    {
        $violation = new Violation(['title'], 'required', 'is required.');

        self::assertSame(
            '{"path":["title"],"code":"required","message":"is required.","parameters":{}}',
            json_encode($violation, JSON_THROW_ON_ERROR),
        );
    }

    public function test_a_list_valued_parameter_survives_as_a_json_array(): void
    {
        $violation = new Violation(['role'], 'in', 'must be one of the allowed values.', [
            'allowed' => ['admin', 'editor'],
        ]);

        self::assertSame(
            '{"path":["role"],"code":"in","message":"must be one of the allowed values.",'
            . '"parameters":{"allowed":["admin","editor"]}}',
            json_encode($violation, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Every rejected shape an application could hand this constructor.
     * Coercing any of them would move the failure to the transport
     * edge, where the stack no longer names the rule that built it.
     *
     * @return iterable<string, array{0: callable(): mixed, 1: string}>
     */
    public static function malformedViolations(): iterable
    {
        yield 'a path that is a map, not a list' => [
            static fn (): mixed => new Violation(['field' => 'name'], 'code', 'message.'),
            'A violation path must be a list of segments.',
        ];

        yield 'a path segment that is neither a string nor an int' => [
            static fn (): mixed => new Violation([1.5], 'code', 'message.'),
            'A violation path segment must be a string or an int, float given.',
        ];

        yield 'an empty code' => [
            static fn (): mixed => new Violation(['name'], '', 'message.'),
            'A violation code must not be empty.',
        ];

        yield 'an empty message' => [
            static fn (): mixed => new Violation(['name'], 'code', ''),
            'A violation message must not be empty.',
        ];

        yield 'a parameter name that is not a string' => [
            static fn (): mixed => new Violation(['name'], 'code', 'message.', [0 => 'value']),
            'A violation parameter name must be a string.',
        ];

        yield 'a parameter holding an object' => [
            static fn (): mixed => new Violation(['name'], 'code', 'message.', ['at' => new \DateTimeImmutable()]),
            'Violation parameter "at" must be a scalar, null, or a list of scalars, DateTimeImmutable given.',
        ];

        yield 'a parameter holding a map' => [
            static fn (): mixed => new Violation(['name'], 'code', 'message.', ['bounds' => ['min' => 1]]),
            'Violation parameter "bounds" must be a scalar, null, or a list of scalars, array given.',
        ];

        yield 'a parameter list holding a non-scalar' => [
            static fn (): mixed => new Violation(['name'], 'code', 'message.', ['allowed' => ['a', ['b']]]),
            'Violation parameter "allowed" must hold only scalars, array given.',
        ];

        yield 'a parameter list holding null' => [
            static fn (): mixed => new Violation(['name'], 'code', 'message.', ['allowed' => ['a', null]]),
            'Violation parameter "allowed" must hold only scalars, null given.',
        ];

        yield 'a parameter holding INF' => [
            static fn (): mixed => new Violation(['price'], 'code', 'message.', ['max' => INF]),
            'Violation parameter "max" must hold only finite floats, INF given.',
        ];

        yield 'a parameter holding -INF' => [
            static fn (): mixed => new Violation(['price'], 'code', 'message.', ['min' => -INF]),
            'Violation parameter "min" must hold only finite floats, -INF given.',
        ];

        yield 'a parameter holding NAN' => [
            static fn (): mixed => new Violation(['price'], 'code', 'message.', ['given' => NAN]),
            'Violation parameter "given" must hold only finite floats, NAN given.',
        ];

        yield 'a parameter list holding INF' => [
            static fn (): mixed => new Violation(['price'], 'code', 'message.', ['bounds' => [1.5, INF]]),
            'Violation parameter "bounds" must hold only finite floats, INF given.',
        ];

        yield 'a parameter list holding NAN' => [
            static fn (): mixed => new Violation(['price'], 'code', 'message.', ['bounds' => [1.5, NAN]]),
            'Violation parameter "bounds" must hold only finite floats, NAN given.',
        ];
    }

    /**
     * @param callable(): mixed $construct
     */
    #[DataProvider('malformedViolations')]
    public function test_a_malformed_violation_is_refused_at_construction(callable $construct, string $expected): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expected);

        $construct();
    }

    /**
     * The shapes next to the rejected ones, so the checks above are
     * proven to reject something rather than everything.
     */
    public function test_the_accepted_parameter_domain_is_not_narrower_than_it_claims(): void
    {
        $violation = new Violation([''], 'code', 'message.', [
            'string' => 'a',
            'int' => 1,
            'float' => 1.5,
            'bool' => true,
            'null' => null,
            'list' => ['a', 1, 1.5, false],
        ]);

        self::assertSame([''], $violation->path, 'an empty member name is a real JSON key, not a malformed segment');
        self::assertCount(6, $violation->parameters);
    }

    /**
     * The finiteness rule rejects exactly what JSON cannot spell, and
     * nothing else: an ordinary float — a bound a numeric rule reports,
     * say — still encodes as itself, alone and inside a list.
     */
    public function test_finite_floats_are_kept_and_encode_as_themselves(): void
    {
        $violation = new Violation(['price'], 'between', 'must be between the bounds.', [
            'min' => 0.5,
            'max' => -1.25,
            'bounds' => [0.5, -1.25],
        ]);

        self::assertSame(
            '{"path":["price"],"code":"between","message":"must be between the bounds.",'
            . '"parameters":{"min":0.5,"max":-1.25,"bounds":[0.5,-1.25]}}',
            json_encode($violation, JSON_THROW_ON_ERROR),
        );
    }
}
