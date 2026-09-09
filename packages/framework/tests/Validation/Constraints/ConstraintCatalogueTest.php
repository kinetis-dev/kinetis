<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Constraints;

use Kinetis\Validation\Constraint;
use Kinetis\Validation\Constraints\Date;
use Kinetis\Validation\Constraints\DateTime;
use Kinetis\Validation\Constraints\Email;
use Kinetis\Validation\Constraints\FileExtension;
use Kinetis\Validation\Constraints\FileSize;
use Kinetis\Validation\Constraints\GreaterThan;
use Kinetis\Validation\Constraints\GreaterThanOrEqual;
use Kinetis\Validation\Constraints\In;
use Kinetis\Validation\Constraints\Ip;
use Kinetis\Validation\Constraints\LessThan;
use Kinetis\Validation\Constraints\LessThanOrEqual;
use Kinetis\Validation\Constraints\MaxItems;
use Kinetis\Validation\Constraints\MaxLength;
use Kinetis\Validation\Constraints\MinItems;
use Kinetis\Validation\Constraints\MinLength;
use Kinetis\Validation\Constraints\MultipleOf;
use Kinetis\Validation\Constraints\NotBlank;
use Kinetis\Validation\Constraints\NotIn;
use Kinetis\Validation\Constraints\Regex;
use Kinetis\Validation\Constraints\Url;
use Kinetis\Validation\Constraints\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What every built-in rule says about itself: the violation a broken
 * value gets — its own code, sentence and parameters, at the empty path
 * that means "the value I was given" — and the JSON Schema keywords that
 * state the same rule to a client.
 *
 * These two are asserted together on purpose. A rule whose keywords
 * describe a bound its check doesn't enforce, or vice versa, publishes a
 * contract requests are not held to, and that is exactly what one class
 * owning both answers is meant to prevent.
 */
final class ConstraintCatalogueTest extends TestCase
{
    /**
     * @return iterable<string, array{Constraint, mixed, string, string, array<string, mixed>}>
     */
    public static function brokenValues(): iterable
    {
        yield 'email' => [new Email(), 'not-an-email', 'email', 'must be a valid email address.', []];
        yield 'not blank' => [new NotBlank(), '   ', 'not_blank', 'must not be blank.', []];
        yield 'min length' => [new MinLength(3), 'ab', 'min_length', 'must be at least 3 characters.', ['length' => 3]];
        yield 'max length' => [new MaxLength(2), 'abc', 'max_length', 'must be at most 2 characters.', ['length' => 2]];
        yield 'greater than' => [new GreaterThan(0), 0, 'greater_than', 'must be greater than 0.', ['threshold' => 0]];
        yield 'less than' => [new LessThan(1.5), 1.5, 'less_than', 'must be less than 1.5.', ['threshold' => 1.5]];
        yield 'regex' => [
            new Regex('/^[A-Z]+$/'),
            'abc',
            'regex',
            'must match the pattern /^[A-Z]+$/.',
            ['pattern' => '/^[A-Z]+$/'],
        ];
        yield 'in' => [
            new In(['admin', 'member']),
            'guest',
            'in',
            'must be one of: admin, member.',
            ['choices' => ['admin', 'member']],
        ];
        yield 'not in' => [
            new NotIn(['root', 'admin']),
            'admin',
            'not_in',
            'must not be one of: root, admin.',
            ['choices' => ['root', 'admin']],
        ];
        yield 'greater than or equal' => [
            new GreaterThanOrEqual(1),
            0,
            'greater_than_or_equal',
            'must be greater than or equal to 1.',
            ['threshold' => 1],
        ];
        yield 'less than or equal' => [
            new LessThanOrEqual(1.5),
            1.6,
            'less_than_or_equal',
            'must be less than or equal to 1.5.',
            ['threshold' => 1.5],
        ];
        yield 'multiple of' => [new MultipleOf(6), 13, 'multiple_of', 'must be a multiple of 6.', ['divisor' => 6]];
        yield 'ip' => [new Ip(), '10.0.0.256', 'ip', 'must be a valid IP address.', []];
        yield 'date' => [new Date(), '2023-02-29', 'date', 'must be a calendar date in YYYY-MM-DD form.', []];
        yield 'date-time' => [new DateTime(), '2024-01-01 10:00:00Z', 'date_time', 'must be an RFC 3339 date-time.', []];
        yield 'min items' => [new MinItems(2), ['a'], 'min_items', 'must contain at least 2 items.', ['count' => 2]];
        yield 'max items' => [new MaxItems(1), ['a', 'b'], 'max_items', 'must contain at most 1 items.', ['count' => 1]];
        yield 'url' => [new Url(), 'not a url', 'url', 'must be a valid URL.', []];
        yield 'uuid' => [new Uuid(), 'not-a-uuid', 'uuid', 'must be a valid UUID.', []];
        yield 'greater than, non-number' => [new GreaterThan(0), 'nope', 'not_a_number', 'must be a number.', []];
        yield 'less than, non-number' => [new LessThan(0), 'nope', 'not_a_number', 'must be a number.', []];
        yield 'greater than or equal, non-number' => [
            new GreaterThanOrEqual(0),
            'nope',
            'not_a_number',
            'must be a number.',
            [],
        ];
        yield 'less than or equal, non-number' => [new LessThanOrEqual(0), 'nope', 'not_a_number', 'must be a number.', []];
        yield 'multiple of, a float' => [new MultipleOf(6), 12.0, 'not_an_integer', 'must be an integer.', []];
        yield 'multiple of, a numeric string' => [new MultipleOf(6), '12', 'not_an_integer', 'must be an integer.', []];
        // The string family folds a wrong direct value into its own
        // rule code rather than a shared shape code: through a request
        // the declared type has already been checked, and a rule asked
        // directly still answers about its own subject.
        yield 'ip, a non-string' => [new Ip(), 7, 'ip', 'must be a valid IP address.', []];
        yield 'date, a non-string' => [new Date(), 20240101, 'date', 'must be a calendar date in YYYY-MM-DD form.', []];
        yield 'date-time, a non-string' => [new DateTime(), null, 'date_time', 'must be an RFC 3339 date-time.', []];
        yield 'min items, non-list' => [new MinItems(1), 'nope', 'not_a_list', 'must be a JSON array.', []];
        yield 'max items, non-list' => [new MaxItems(1), 'nope', 'not_a_list', 'must be a JSON array.', []];
        // The file family names its own subject the same way: a rule
        // about an uploaded file, asked about anything else, says so
        // rather than reporting a bound it never measured.
        yield 'file size, a non-file' => [new FileSize(10), 'nope', 'not_a_file', 'must be an uploaded file.', []];
        yield 'file extension, a non-file' => [
            new FileExtension(['png']),
            'nope',
            'not_a_file',
            'must be an uploaded file.',
            [],
        ];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    #[DataProvider('brokenValues')]
    public function test_a_broken_rule_answers_with_its_own_code_message_and_parameters(
        Constraint $constraint,
        mixed $value,
        string $code,
        string $message,
        array $parameters,
    ): void {
        $violation = $constraint->validate($value);

        self::assertNotNull($violation);
        // Relative to the value, always: prefixing the owning field is
        // Hydrator's job, and a rule that named a field itself would be
        // wrong the moment the same rule guarded a different one.
        self::assertSame([], $violation->path);
        self::assertSame($code, $violation->code);
        self::assertSame($message, $violation->message);
        self::assertSame($parameters, $violation->parameters);
    }

    /**
     * @return iterable<string, array{Constraint, mixed}>
     */
    public static function acceptedValues(): iterable
    {
        yield 'email' => [new Email(), 'alon@example.com'];
        yield 'not blank' => [new NotBlank(), ' x '];
        yield 'min length' => [new MinLength(3), 'abc'];
        yield 'max length' => [new MaxLength(3), 'abc'];
        yield 'greater than' => [new GreaterThan(0), 1];
        yield 'less than' => [new LessThan(1.5), 1.4];
        yield 'regex' => [new Regex('/^[A-Z]+$/'), 'ABC'];
        yield 'in' => [new In(['admin', 'member']), 'member'];
        yield 'not in' => [new NotIn(['root', 'admin']), 'member'];
        yield 'greater than or equal, above' => [new GreaterThanOrEqual(1), 2];
        yield 'greater than or equal, at the bound' => [new GreaterThanOrEqual(1), 1];
        yield 'less than or equal, below' => [new LessThanOrEqual(1.5), 1.4];
        yield 'less than or equal, at the bound' => [new LessThanOrEqual(1.5), 1.5];
        yield 'multiple of' => [new MultipleOf(6), 12];
        yield 'ip' => [new Ip(), '192.168.1.1'];
        yield 'date' => [new Date(), '2024-02-29'];
        yield 'date-time' => [new DateTime(), '2024-01-01T10:00:00Z'];
        yield 'min items' => [new MinItems(2), ['a', 'b']];
        yield 'max items' => [new MaxItems(1), ['a']];
        yield 'url' => [new Url(), 'https://example.com/x'];
        yield 'uuid' => [new Uuid(), '9f8c2b1e-4d3a-4f6b-9c2d-0a1b2c3d4e5f'];
    }

    #[DataProvider('acceptedValues')]
    public function test_a_satisfied_rule_answers_with_null(Constraint $constraint, mixed $value): void
    {
        self::assertNull($constraint->validate($value));
    }

    /**
     * @return iterable<string, array{Constraint, array<string, mixed>}>
     */
    public static function schemas(): iterable
    {
        yield 'email' => [new Email(), ['format' => 'email']];
        yield 'min length' => [new MinLength(5), ['minLength' => 5]];
        yield 'max length' => [new MaxLength(20), ['maxLength' => 20]];
        yield 'greater than' => [new GreaterThan(0), ['exclusiveMinimum' => 0]];
        yield 'less than' => [new LessThan(120), ['exclusiveMaximum' => 120]];
        yield 'in' => [new In(['admin', 'member']), ['enum' => ['admin', 'member']]];
        yield 'not in' => [new NotIn(['root']), ['not' => ['enum' => ['root']]]];
        yield 'greater than or equal' => [new GreaterThanOrEqual(1), ['minimum' => 1]];
        yield 'less than or equal' => [new LessThanOrEqual(120), ['maximum' => 120]];
        yield 'multiple of' => [new MultipleOf(6), ['multipleOf' => 6]];
        yield 'ip' => [new Ip(), ['anyOf' => [['format' => 'ipv4'], ['format' => 'ipv6']]]];
        yield 'date' => [new Date(), ['format' => 'date']];
        yield 'date-time' => [new DateTime(), ['format' => 'date-time']];
        yield 'min items' => [new MinItems(1), ['minItems' => 1]];
        yield 'max items' => [new MaxItems(3), ['maxItems' => 3]];
        yield 'url' => [new Url(), ['format' => 'uri']];
        yield 'uuid' => [new Uuid(), ['format' => 'uuid']];
        // #[NotBlank] and #[Regex] are runtime-only: JSON Schema's
        // `minLength: 1` admits "   ", and its `pattern` is an
        // undelimited ECMA-262 expression, a different dialect from the
        // delimited PHP PCRE #[Regex] takes. Publishing either would
        // state a rule the request is not checked against.
        yield 'not blank' => [new NotBlank(), []];
        yield 'regex' => [new Regex('/^[A-Z]+$/'), []];
        // #[FileSize] and #[FileExtension] are runtime-only for the
        // same reason: both describe a multipart part, which a
        // `{type: string, format: binary}` schema stands for without
        // publishing its byte count or the name the client wrote.
        yield 'file size' => [new FileSize(10), []];
        yield 'file extension' => [new FileExtension(['png']), []];
    }

    /**
     * @param array<string, mixed> $schema
     */
    #[DataProvider('schemas')]
    public function test_a_rule_states_its_own_json_schema_keywords(Constraint $constraint, array $schema): void
    {
        self::assertSame($schema, $constraint->schema());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function addresses(): iterable
    {
        yield 'IPv4' => ['192.168.1.1', true];
        yield 'IPv6' => ['2001:db8::1', true];
        yield 'the IPv6 loopback' => ['::1', true];
        yield 'an IPv4-mapped IPv6 address' => ['::ffff:192.0.2.128', true];
        // Each of these carries something an address does not: a
        // link-local interface, a network's size, a URI authority's
        // punctuation, an octet spelled in a base the value never names,
        // three of four octets, or the whitespace of the line it was
        // read from.
        yield 'a zone identifier' => ['fe80::1%eth0', false];
        yield 'a CIDR block' => ['192.168.1.0/24', false];
        yield 'bracket notation' => ['[::1]', false];
        yield 'a leading-zero octet' => ['192.168.01.1', false];
        yield 'a short IPv4 address' => ['192.168.1', false];
        yield 'surrounding whitespace' => [' 192.168.1.1 ', false];
        yield 'a trailing newline' => ["192.168.1.1\n", false];
    }

    #[DataProvider('addresses')]
    public function test_ip_reads_an_address_and_nothing_around_it(string $value, bool $accepted): void
    {
        self::assertSame($accepted, new Ip()->validate($value) === null);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function identifiers(): iterable
    {
        yield 'a version 4 UUID' => ['9f8c2b1e-4d3a-4f6b-9c2d-0a1b2c3d4e5f', true];
        yield 'the nil UUID' => ['00000000-0000-0000-0000-000000000000', true];
        yield 'the max UUID' => ['ffffffff-ffff-ffff-ffff-ffffffffffff', true];
        yield 'a version 7 UUID' => ['018f6b1a-9c2d-7f3e-8a1b-2c3d4e5f6071', true];
        yield 'an uppercase UUID' => ['9F8C2B1E-4D3A-4F6B-9C2D-0A1B2C3D4E5F', true];
        yield 'a trailing newline' => ["9f8c2b1e-4d3a-4f6b-9c2d-0a1b2c3d4e5f\n", false];
        yield 'a group of the wrong length' => ['9f8c2b1e-4d3a-4f6b-9c2d-0a1b2c3d4e5', false];
        yield 'a non-hex digit' => ['9f8c2b1g-4d3a-4f6b-9c2d-0a1b2c3d4e5f', false];
        yield 'no groups at all' => ['9f8c2b1e4d3a4f6b9c2d0a1b2c3d4e5f', false];
    }

    /**
     * The rule is RFC 9562's string form and nothing else. Reading the
     * version or variant nibble would reject identifiers the RFC defines
     * and applications issue, for a spelling a client cannot change.
     */
    #[DataProvider('identifiers')]
    public function test_uuid_admits_every_rfc_9562_string_form(string $value, bool $accepted): void
    {
        self::assertSame($accepted, new Uuid()->validate($value) === null);
    }

    /**
     * Divisibility is a question about whole numbers, and the answer for
     * a negative multiple and for zero is yes. `PHP_INT_MIN` is the one
     * value where the remainder operator could overflow, and a divisor
     * of 1 is where it would: the constructor's own `>= 1` bound is what
     * keeps the negation that would produce it out of reach.
     */
    public function test_multiple_of_counts_negative_multiples_zero_and_the_whole_int_range(): void
    {
        self::assertNull(new MultipleOf(6)->validate(-12));
        self::assertNull(new MultipleOf(6)->validate(0));
        self::assertNull(new MultipleOf(1)->validate(PHP_INT_MIN));
        self::assertNull(new MultipleOf(1)->validate(PHP_INT_MAX));
    }

    /**
     * Exclusion is strict, so it is about a value's type as much as its
     * spelling: the integer 1 is not the string the set names.
     */
    public function test_not_in_excludes_by_identity_rather_than_by_loose_equality(): void
    {
        self::assertNull(new NotIn(['1'])->validate(1));
        self::assertSame('not_in', new NotIn(['1'])->validate('1')?->code);
    }
}
