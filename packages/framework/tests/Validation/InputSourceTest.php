<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation;

use Kinetis\Tests\Validation\Fixtures\ApplicationRulesRequest;
use Kinetis\Tests\Validation\Fixtures\SourceSpellingsRequest;
use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\Hydrator;
use Kinetis\Validation\InputSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which spelling of each scalar type binds, per source.
 *
 * The point of the three sources is that they disagree, so every case
 * here is written as a spelling one source takes and another refuses. A
 * change that quietly widened Json back to Native's spellings would show
 * up as the JSON rejections below passing a value through.
 */
final class InputSourceTest extends TestCase
{
    private const array JSON_PRIMITIVES = [
        'count' => 42,
        'ratio' => 1.5,
        'flag' => true,
        'label' => 'x',
    ];

    /**
     * @param array<string, mixed> $data
     * @return list<array{path: list<string|int>, code: string}>
     */
    private static function failures(array $data, InputSource $source): array
    {
        try {
            Hydrator::hydrate(SourceSpellingsRequest::class, $data, source: $source);
        } catch (ValidationException $e) {
            return array_map(
                static fn ($violation): array => ['path' => $violation->path, 'code' => $violation->code],
                $e->violations,
            );
        }

        self::fail('Expected a ValidationException.');
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function payload(array $overrides = []): array
    {
        return [...self::JSON_PRIMITIVES, ...$overrides];
    }

    public function test_json_binds_the_json_primitive_of_each_declared_type(): void
    {
        $request = Hydrator::hydrate(SourceSpellingsRequest::class, self::payload(), source: InputSource::Json);

        self::assertSame(42, $request->count);
        self::assertSame(1.5, $request->ratio);
        self::assertTrue($request->flag);
        self::assertSame('x', $request->label);
    }

    /**
     * JSON has one number type, so an integer written with a trailing
     * `.0` is still an integer — the one non-obvious JSON spelling an
     * `int` field takes. A fractional or out-of-range one is not.
     */
    public function test_json_binds_a_finite_integral_float_to_an_int_field(): void
    {
        $request = Hydrator::hydrate(
            SourceSpellingsRequest::class,
            self::payload(['count' => 42.0]),
            source: InputSource::Json,
        );

        self::assertSame(42, $request->count);
    }

    public function test_json_rejects_a_fractional_float_for_an_int_field(): void
    {
        self::assertSame(
            [['path' => ['count'], 'code' => 'not_an_integer']],
            self::failures(self::payload(['count' => 4.5]), InputSource::Json),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<string|int>}>
     */
    public static function jsonRejectedStrings(): iterable
    {
        yield 'a numeric string for int' => [['count' => '42'], ['count']];
        yield 'a decimal string for float' => [['ratio' => '1.5'], ['ratio']];
        yield 'the word true for bool' => [['flag' => 'true'], ['flag']];
        yield 'the digit one as a string for bool' => [['flag' => '1'], ['flag']];
    }

    /**
     * A JSON document distinguishes `42` from `"42"`, and the schema a
     * route or an MCP tool publishes for these fields says `integer`,
     * `number` and `boolean`. Taking the string too would make that
     * document untrue.
     *
     * @param array<string, mixed> $overrides
     * @param list<string|int> $path
     */
    #[DataProvider('jsonRejectedStrings')]
    public function test_json_rejects_a_textual_spelling(array $overrides, array $path): void
    {
        self::assertSame(
            [['path' => $path, 'code' => 'type_mismatch']],
            self::failures(self::payload($overrides), InputSource::Json),
        );
    }

    /**
     * `1` and `0` are a textual and database convention for a boolean,
     * not a JSON one: JSON spells a boolean `true`/`false` and nothing
     * else.
     */
    public function test_json_rejects_the_numeric_boolean_convention(): void
    {
        self::assertSame(
            [['path' => ['flag'], 'code' => 'type_mismatch']],
            self::failures(self::payload(['flag' => 1]), InputSource::Json),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function textSpellings(): iterable
    {
        yield 'a plain base-10 integer' => [['count' => '7'], ['count' => 7]];
        yield 'a signed integer' => [['count' => '-7'], ['count' => -7]];
        yield 'a decimal number' => [['ratio' => '2.5'], ['ratio' => 2.5]];
        yield 'an integer for a float field' => [['ratio' => '3'], ['ratio' => 3.0]];
        yield 'the word true' => [['flag' => 'true'], ['flag' => true]];
        yield 'the word false' => [['flag' => 'false'], ['flag' => false]];
        yield 'the digit one' => [['flag' => '1'], ['flag' => true]];
        yield 'the digit zero' => [['flag' => '0'], ['flag' => false]];
    }

    /**
     * @param array<string, mixed> $overrides
     * @param array<string, mixed> $expected
     */
    #[DataProvider('textSpellings')]
    public function test_text_binds_each_canonical_textual_spelling(array $overrides, array $expected): void
    {
        $data = [...['count' => '1', 'ratio' => '1', 'flag' => '1', 'label' => 'x'], ...$overrides];
        $request = Hydrator::hydrate(SourceSpellingsRequest::class, $data, source: InputSource::Text);

        foreach ($expected as $field => $value) {
            self::assertSame($value, $request->{$field});
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<string|int>, string}>
     */
    public static function textRejections(): iterable
    {
        yield 'a decimal spelling of an integer' => [['count' => '42.0'], ['count'], 'not_an_integer'];
        yield 'an exponent spelling of an integer' => [['count' => '4.2e1'], ['count'], 'not_an_integer'];
        yield 'a whitespace-padded integer' => [['count' => ' 42'], ['count'], 'not_an_integer'];
        yield 'a non-numeric string for a float' => [['ratio' => 'abc'], ['ratio'], 'type_mismatch'];
        yield 'an overflowing float' => [['ratio' => '1e999'], ['ratio'], 'not_finite'];
        yield 'a boolean spelled neither way' => [['flag' => 'yes'], ['flag'], 'type_mismatch'];
        // A repeated query key or a bracketed form field name produces a
        // real PHP array; a scalar field takes none of them.
        yield 'an array for a scalar field' => [['label' => ['a', 'b']], ['label'], 'type_mismatch'];
        yield 'an array for an int field' => [['count' => ['1']], ['count'], 'type_mismatch'];
    }

    /**
     * @param array<string, mixed> $overrides
     * @param list<string|int> $path
     */
    #[DataProvider('textRejections')]
    public function test_text_rejects_a_noncanonical_spelling(array $overrides, array $path, string $code): void
    {
        $data = [...['count' => '1', 'ratio' => '1', 'flag' => '1', 'label' => 'x'], ...$overrides];

        self::assertSame([['path' => $path, 'code' => $code]], self::failures($data, InputSource::Text));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<string|int>}>
     */
    public static function textNativePrimitives(): iterable
    {
        yield 'a native int for an int field' => [['count' => 42], ['count']];
        yield 'a native float for a float field' => [['ratio' => 1.5], ['ratio']];
        yield 'a native int for a float field' => [['ratio' => 2], ['ratio']];
        yield 'a native bool for a bool field' => [['flag' => true], ['flag']];
        yield 'the numeric boolean convention for a bool field' => [['flag' => 1], ['flag']];
    }

    /**
     * Text is text. A query string, a path segment and a form body
     * carry raw strings and have no way to write a PHP `int`, `float` or
     * `bool`, so those are not spellings this source has — the same
     * reason Json refuses `"42"`. Taking one anyway would make the
     * source's own documented domain untrue and would quietly bind a
     * value for a caller that named the wrong source for the bytes it
     * read.
     *
     * @param array<string, mixed> $overrides
     * @param list<string|int> $path
     */
    #[DataProvider('textNativePrimitives')]
    public function test_text_rejects_a_native_primitive(array $overrides, array $path): void
    {
        $data = [...['count' => '1', 'ratio' => '1', 'flag' => '1', 'label' => 'x'], ...$overrides];

        self::assertSame([['path' => $path, 'code' => 'type_mismatch']], self::failures($data, InputSource::Text));
    }

    /**
     * The same values under Native, which is where they belong: a
     * rejection above is about the source, never about the value.
     */
    public function test_the_native_primitives_text_rejects_bind_under_native(): void
    {
        $request = Hydrator::hydrate(
            SourceSpellingsRequest::class,
            ['count' => 42, 'ratio' => 1.5, 'flag' => true, 'label' => 'x'],
            source: InputSource::Native,
        );

        self::assertSame(42, $request->count);
        self::assertSame(1.5, $request->ratio);
        self::assertTrue($request->flag);
    }

    /**
     * A driver decides for itself whether a column arrives as an int or
     * as its decimal string, and a `TINYINT(1)` boolean arrives as `1`
     * or `"1"` depending on the connection — so a row handed straight to
     * hydrate() binds all of them. This is the default source, which is
     * what keeps `Kinetis\QueryBuilder\Query`'s row hydration working
     * with no call-site change.
     */
    public function test_native_binds_the_spellings_a_database_row_carries(): void
    {
        $row = ['count' => '42', 'ratio' => '1.50', 'flag' => 1, 'label' => 'x'];
        $request = Hydrator::hydrate(SourceSpellingsRequest::class, $row);

        self::assertSame(42, $request->count);
        self::assertSame(1.5, $request->ratio);
        self::assertTrue($request->flag);

        $offRow = ['count' => 42, 'ratio' => 1.5, 'flag' => '0', 'label' => 'x'];

        self::assertFalse(Hydrator::hydrate(SourceSpellingsRequest::class, $offRow)->flag);
    }

    /**
     * Native is what hydrate() means when no source is named, so the
     * default and the named case cannot drift apart.
     */
    public function test_native_is_the_default_source(): void
    {
        $row = ['count' => '42', 'ratio' => '1.5', 'flag' => '1', 'label' => 'x'];

        self::assertEquals(
            Hydrator::hydrate(SourceSpellingsRequest::class, $row),
            Hydrator::hydrate(SourceSpellingsRequest::class, $row, source: InputSource::Native),
        );
    }

    /**
     * An accepted null is the value; a rule describes what a present
     * value must look like, and the declared type has already said null
     * is one of the things this field may be. Running #[MinLength(3)]
     * against it would report a length failure for a field the DTO
     * explicitly permits to be absent.
     */
    public function test_an_accepted_null_skips_the_fields_rules(): void
    {
        $request = Hydrator::hydrate(
            SourceSpellingsRequest::class,
            self::payload(['nickname' => null]),
            source: InputSource::Json,
        );

        self::assertNull($request->nickname);

        $text = Hydrator::hydrate(
            SourceSpellingsRequest::class,
            ['count' => '1', 'ratio' => '1', 'flag' => '1', 'label' => 'x', 'nickname' => null],
            source: InputSource::Text,
        );

        self::assertNull($text->nickname);
    }

    public function test_a_present_non_null_value_still_runs_the_fields_rules(): void
    {
        self::assertSame(
            [['path' => ['nickname'], 'code' => 'min_length']],
            self::failures(self::payload(['nickname' => 'ab']), InputSource::Json),
        );
    }

    /**
     * The compiled artifact stores literal descriptors only, so a plan
     * compiled ahead of time and one derived live must reach the same
     * answer for the same source — including the rejections above, which
     * are the ones a stale or differently-built plan could soften.
     */
    public function test_a_compiled_plan_reaches_the_identical_outcome_under_every_source(): void
    {
        $plan = Hydrator::compilePlan(SourceSpellingsRequest::class);
        $data = self::payload(['count' => '42', 'flag' => '1']);

        foreach ([InputSource::Json, InputSource::Text, InputSource::Native] as $source) {
            $live = null;
            $compiled = null;

            try {
                $live = Hydrator::hydrate(SourceSpellingsRequest::class, $data, source: $source);
            } catch (ValidationException $e) {
                $live = $e->grouped();
            }

            try {
                $compiled = Hydrator::hydrate(SourceSpellingsRequest::class, $data, $plan, $source);
            } catch (ValidationException $e) {
                $compiled = $e->grouped();
            }

            self::assertEquals($live, $compiled, $source->name);
        }
    }

    /**
     * An application rule needs no registration anywhere: the same
     * attribute reading that finds #[Email] finds it, and the violation
     * it hands back is the one that reaches the client, code and all.
     */
    public function test_an_application_constraint_is_honored_by_hydration(): void
    {
        self::assertSame('ABC', Hydrator::hydrate(ApplicationRulesRequest::class, ['code' => 'ABC'])->code);

        try {
            Hydrator::hydrate(ApplicationRulesRequest::class, ['code' => 'abc']);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertCount(1, $e->violations);
            self::assertSame(['code'], $e->violations[0]->path);
            self::assertSame('uppercase', $e->violations[0]->code);
            self::assertSame('must be all uppercase letters.', $e->violations[0]->message);
        }
    }

    /**
     * A rule with no JSON Schema keyword still runs — the empty schema
     * is about what a document can state, never about whether the check
     * happens.
     */
    public function test_a_runtime_only_application_constraint_still_runs(): void
    {
        try {
            Hydrator::hydrate(ApplicationRulesRequest::class, ['code' => 'ROOT']);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame('not_reserved', $e->violations[0]->code);
            self::assertSame(['reserved' => ['ROOT', 'ADMIN']], $e->violations[0]->parameters);
        }
    }
}
