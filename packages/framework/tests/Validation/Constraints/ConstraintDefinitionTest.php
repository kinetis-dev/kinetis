<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Constraints;

use Closure;
use InvalidArgumentException;
use Kinetis\Validation\Constraint;
use Kinetis\Validation\Constraints\GreaterThan;
use Kinetis\Validation\Constraints\GreaterThanOrEqual;
use Kinetis\Validation\Constraints\In;
use Kinetis\Validation\Constraints\LessThan;
use Kinetis\Validation\Constraints\LessThanOrEqual;
use Kinetis\Validation\Constraints\MaxLength;
use Kinetis\Validation\Constraints\MinLength;
use Kinetis\Validation\Constraints\MultipleOf;
use Kinetis\Validation\Constraints\NotIn;
use Kinetis\Validation\Constraints\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What each built-in refuses to be declared as. A rule's constructor
 * arguments now reach a client twice — as a violation's parameters and
 * as a published JSON Schema keyword — so a definition with no truthful
 * form in either fails where it is written, not on the request that
 * happens to hit it. #[MinItems]/#[MaxItems]' own bound is in
 * ItemCountTest, and #[FileSize]/#[FileExtension]' own in
 * FileRulesTest.
 */
final class ConstraintDefinitionTest extends TestCase
{
    /**
     * @return iterable<string, array{Closure(): Constraint, string}>
     */
    public static function rejectedDefinitions(): iterable
    {
        yield 'a negative minimum length' => [
            static fn (): Constraint => new MinLength(-1),
            'MinLength length must not be negative, got -1.',
        ];
        yield 'a negative maximum length' => [
            static fn (): Constraint => new MaxLength(-2),
            'MaxLength length must not be negative, got -2.',
        ];
        yield 'an infinite lower bound' => [
            static fn (): Constraint => new GreaterThan(INF),
            'GreaterThan threshold must be a finite number, INF given.',
        ];
        yield 'a NAN lower bound' => [
            static fn (): Constraint => new GreaterThan(NAN),
            'GreaterThan threshold must be a finite number, NAN given.',
        ];
        yield 'an infinite upper bound' => [
            static fn (): Constraint => new LessThan(-INF),
            'LessThan threshold must be a finite number, -INF given.',
        ];
        yield 'a NAN upper bound' => [
            static fn (): Constraint => new LessThan(NAN),
            'LessThan threshold must be a finite number, NAN given.',
        ];
        yield 'an empty choice set' => [
            static fn (): Constraint => new In([]),
            'In choices must be a non-empty list of scalars.',
        ];
        yield 'a keyed choice set' => [
            static fn (): Constraint => new In(['admin' => 'Administrator']),
            'In choices must be a non-empty list of scalars.',
        ];
        yield 'a non-scalar choice' => [
            static fn (): Constraint => new In([['admin']]),
            'In choices must be scalars, array given.',
        ];
        yield 'a non-finite choice' => [
            static fn (): Constraint => new In([1.5, INF]),
            'In choices must be finite numbers, INF given.',
        ];
        yield 'an infinite inclusive lower bound' => [
            static fn (): Constraint => new GreaterThanOrEqual(INF),
            'GreaterThanOrEqual threshold must be a finite number, INF given.',
        ];
        yield 'a NAN inclusive lower bound' => [
            static fn (): Constraint => new GreaterThanOrEqual(NAN),
            'GreaterThanOrEqual threshold must be a finite number, NAN given.',
        ];
        yield 'an infinite inclusive upper bound' => [
            static fn (): Constraint => new LessThanOrEqual(-INF),
            'LessThanOrEqual threshold must be a finite number, -INF given.',
        ];
        yield 'a NAN inclusive upper bound' => [
            static fn (): Constraint => new LessThanOrEqual(NAN),
            'LessThanOrEqual threshold must be a finite number, NAN given.',
        ];
        yield 'an empty excluded set' => [
            static fn (): Constraint => new NotIn([]),
            'NotIn choices must be a non-empty list of scalars.',
        ];
        yield 'a keyed excluded set' => [
            static fn (): Constraint => new NotIn(['admin' => 'Administrator']),
            'NotIn choices must be a non-empty list of scalars.',
        ];
        yield 'a non-scalar excluded value' => [
            static fn (): Constraint => new NotIn([['admin']]),
            'NotIn choices must be scalars, array given.',
        ];
        yield 'a non-finite excluded value' => [
            static fn (): Constraint => new NotIn([1.5, INF]),
            'NotIn choices must be finite numbers, INF given.',
        ];
        yield 'a zero divisor' => [
            static fn (): Constraint => new MultipleOf(0),
            'MultipleOf divisor must be at least 1, got 0.',
        ];
        yield 'a negative divisor' => [
            static fn (): Constraint => new MultipleOf(-6),
            'MultipleOf divisor must be at least 1, got -6.',
        ];
        yield 'an undelimited pattern' => [
            static fn (): Constraint => new Regex('^[A-Z]+$'),
            'Regex pattern "^[A-Z]+$" is not a valid PCRE.',
        ];
        yield 'an unterminated pattern' => [
            static fn (): Constraint => new Regex('/^[A-Z'),
            'Regex pattern "/^[A-Z" is not a valid PCRE.',
        ];
    }

    /**
     * @param Closure(): Constraint $definition
     */
    #[DataProvider('rejectedDefinitions')]
    public function test_an_impossible_definition_is_refused_at_construction(Closure $definition, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $definition();
    }

    /**
     * @return iterable<string, array{Closure(): Constraint}>
     */
    public static function admittedDefinitions(): iterable
    {
        yield 'a zero minimum length' => [static fn (): Constraint => new MinLength(0)];
        yield 'a zero maximum length' => [static fn (): Constraint => new MaxLength(0)];
        yield 'an integer bound' => [static fn (): Constraint => new GreaterThan(0)];
        yield 'a negative float bound' => [static fn (): Constraint => new LessThan(-1.5)];
        yield 'a single choice' => [static fn (): Constraint => new In(['admin'])];
        yield 'mixed scalar choices' => [static fn (): Constraint => new In([1, 2.5, true, 'x'])];
        yield 'an inclusive integer bound' => [static fn (): Constraint => new GreaterThanOrEqual(0)];
        yield 'a negative inclusive float bound' => [static fn (): Constraint => new LessThanOrEqual(-1.5)];
        yield 'a single excluded value' => [static fn (): Constraint => new NotIn(['root'])];
        yield 'mixed excluded scalars' => [static fn (): Constraint => new NotIn([1, 2.5, true, 'x'])];
        yield 'a divisor of one' => [static fn (): Constraint => new MultipleOf(1)];
        yield 'a slash-delimited pattern' => [static fn (): Constraint => new Regex('/^[A-Z]+$/')];
        yield 'a hash-delimited pattern with a modifier' => [static fn (): Constraint => new Regex('#^x#i')];
    }

    /**
     * The other half: every definition that was legal before these
     * checks existed still is. A guard that also refused a working
     * declaration would break applications rather than protect them.
     *
     * @param Closure(): Constraint $definition
     */
    #[DataProvider('admittedDefinitions')]
    public function test_a_valid_definition_is_admitted(Closure $definition): void
    {
        self::assertInstanceOf(Constraint::class, $definition());
    }

    /**
     * A pattern PCRE cannot compile is a programmer's declaration
     * error, and the diagnostic for it is the exception — never the
     * `preg_match(): Delimiter must not be alphanumeric` warning PHP
     * emits on the way, which in a running application would reach the
     * error log on every request that touched the field. The handler
     * reads error_reporting() the way PHP's own does, so a suppressed
     * diagnostic is invisible to it and an unsuppressed one is not.
     */
    public function test_an_invalid_pattern_reports_the_exception_and_no_warning(): void
    {
        $reported = [];

        set_error_handler(static function (int $severity, string $message) use (&$reported): bool {
            if ((error_reporting() & $severity) !== 0) {
                $reported[] = $message;
            }

            return true;
        });

        try {
            new Regex('^[A-Z]+$');
            self::fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('is not a valid PCRE', $e->getMessage());
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $reported);
    }
}
