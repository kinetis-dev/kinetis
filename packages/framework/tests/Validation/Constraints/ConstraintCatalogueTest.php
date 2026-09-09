<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Constraints;

use Kinetis\Validation\Constraint;
use Kinetis\Validation\Constraints\Email;
use Kinetis\Validation\Constraints\GreaterThan;
use Kinetis\Validation\Constraints\In;
use Kinetis\Validation\Constraints\LessThan;
use Kinetis\Validation\Constraints\MaxItems;
use Kinetis\Validation\Constraints\MaxLength;
use Kinetis\Validation\Constraints\MinItems;
use Kinetis\Validation\Constraints\MinLength;
use Kinetis\Validation\Constraints\NotBlank;
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
        yield 'min items' => [new MinItems(2), ['a'], 'min_items', 'must contain at least 2 items.', ['count' => 2]];
        yield 'max items' => [new MaxItems(1), ['a', 'b'], 'max_items', 'must contain at most 1 items.', ['count' => 1]];
        yield 'url' => [new Url(), 'not a url', 'url', 'must be a valid URL.', []];
        yield 'uuid' => [new Uuid(), 'not-a-uuid', 'uuid', 'must be a valid UUID.', []];
        yield 'greater than, non-number' => [new GreaterThan(0), 'nope', 'not_a_number', 'must be a number.', []];
        yield 'less than, non-number' => [new LessThan(0), 'nope', 'not_a_number', 'must be a number.', []];
        yield 'min items, non-list' => [new MinItems(1), 'nope', 'not_a_list', 'must be a JSON array.', []];
        yield 'max items, non-list' => [new MaxItems(1), 'nope', 'not_a_list', 'must be a JSON array.', []];
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
    }

    /**
     * @param array<string, mixed> $schema
     */
    #[DataProvider('schemas')]
    public function test_a_rule_states_its_own_json_schema_keywords(Constraint $constraint, array $schema): void
    {
        self::assertSame($schema, $constraint->schema());
    }
}
