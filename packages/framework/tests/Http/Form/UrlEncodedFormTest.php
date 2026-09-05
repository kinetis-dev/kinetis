<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Form;

use Kinetis\Http\Form\Exception\FormLimitExceededException;
use Kinetis\Http\Form\FormLimits;
use Kinetis\Http\Form\UrlEncodedForm;
use PHPUnit\Framework\TestCase;

final class UrlEncodedFormTest extends TestCase
{
    private static function limits(): FormLimits
    {
        return new FormLimits(FormLimits::DEFAULT_MAX_BODY_BYTES);
    }

    /**
     * The count comes from the raw body, so a thousand repetitions of one
     * name are a thousand pairs here and would be one leaf afterwards.
     */
    public function test_one_name_repeated_past_the_ceiling_is_refused_though_it_parses_to_one_field(): void
    {
        $body = implode('&', array_fill(0, FormLimits::MAX_INPUT_VARS + 1, 'a=1'));

        $this->expectException(FormLimitExceededException::class);
        $this->expectExceptionMessage('input variables');

        UrlEncodedForm::parse($body, self::limits());
    }

    public function test_a_body_is_parsed_the_way_php_parses_it(): void
    {
        self::assertSame(
            ['name' => 'Url Encoded', 'limit' => '5', 'tags' => ['a', 'b']],
            UrlEncodedForm::parse('name=Url+Encoded&limit=5&tags[]=a&tags[]=b', self::limits()),
        );
    }

    public function test_an_empty_body_is_an_empty_form(): void
    {
        self::assertSame([], UrlEncodedForm::parse('', self::limits()));
    }

    /**
     * The count comes from the raw body, before `parse_str()` runs — the
     * only point at which the real number is still knowable, since past
     * `max_input_vars` that function returns a shorter array and says
     * nothing.
     */
    public function test_one_pair_past_the_ceiling_is_refused_rather_than_parsed_short(): void
    {
        $pairs = [];

        for ($i = 0; $i < FormLimits::MAX_INPUT_VARS; $i++) {
            $pairs[] = "field{$i}=v";
        }

        self::assertCount(FormLimits::MAX_INPUT_VARS, UrlEncodedForm::parse(implode('&', $pairs), self::limits()));

        $pairs[] = 'csrf_token=t';

        $this->expectException(FormLimitExceededException::class);
        $this->expectExceptionMessage('input variables');

        UrlEncodedForm::parse(implode('&', $pairs), self::limits());
    }

    public function test_a_body_nested_past_the_ceiling_is_refused(): void
    {
        $this->expectException(FormLimitExceededException::class);
        $this->expectExceptionMessage('nesting depth');

        UrlEncodedForm::parse('a' . str_repeat('[b]', FormLimits::MAX_NESTING_DEPTH) . '=deep', self::limits());
    }

    /**
     * The depth is read from the raw name, before `parse_str()` runs,
     * because past `max_input_nesting_level` that function drops the
     * whole variable and says nothing at all — not a warning, not a
     * partial value. The first assertion here is that behavior itself:
     * a check run afterwards is a check run on a form that is already
     * missing the field it was measuring.
     */
    public function test_a_name_nested_past_what_the_parser_registers_is_refused_not_silently_dropped(): void
    {
        $deep = 'a' . str_repeat('[b]', 80) . '=deep';

        parse_str($deep, $dropped);
        self::assertSame([], $dropped, 'parse_str() drops a name nested this deep without reporting it');

        $this->expectException(FormLimitExceededException::class);
        $this->expectExceptionMessage('nesting depth');

        UrlEncodedForm::parse($deep . '&kept=1', self::limits());
    }

    /**
     * A name is decoded before its depth is read, the same way
     * `parse_str()` decodes it: `a%5Bb%5D` is the two levels it builds,
     * not the one flat key it looks like.
     */
    public function test_a_percent_encoded_name_is_measured_by_what_it_builds(): void
    {
        self::assertSame(['a' => ['b' => '1']], UrlEncodedForm::parse('a%5Bb%5D=1', self::limits()));

        $this->expectException(FormLimitExceededException::class);
        $this->expectExceptionMessage('nesting depth');

        UrlEncodedForm::parse('a' . str_repeat('%5Bb%5D', FormLimits::MAX_NESTING_DEPTH) . '=deep', self::limits());
    }
}
