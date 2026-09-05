<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Form;

use InvalidArgumentException;
use Kinetis\Config\Config;
use Kinetis\Http\Form\Exception\FormLimitExceededException;
use Kinetis\Http\Form\FormLimits;
use Kinetis\Http\Middleware\Exception\BodyTooLargeException;
use PHPUnit\Framework\TestCase;

/**
 * The contract itself, at each of its edges. The runtime conformance
 * suite proves every adapter meets these ceilings; this proves the
 * ceilings are where they say they are, and that a message crossing one
 * carries a number and nothing from the request.
 */
final class FormLimitsTest extends TestCase
{
    private static function limits(): FormLimits
    {
        return new FormLimits(FormLimits::DEFAULT_MAX_BODY_BYTES);
    }

    public function test_the_byte_ceiling_comes_from_config_and_has_to_be_positive(): void
    {
        self::assertSame(4_096, FormLimits::fromConfig(new Config(['MAX_BODY_SIZE' => '4096']))->maxBodyBytes);
        self::assertSame(
            FormLimits::DEFAULT_MAX_BODY_BYTES,
            FormLimits::fromConfig(new Config([]))->maxBodyBytes,
            'an unconfigured application still has a ceiling',
        );

        $this->expectException(InvalidArgumentException::class);

        FormLimits::fromConfig(new Config(['MAX_BODY_SIZE' => '0']));
    }

    /**
     * The pair count no parsed form can show: `a=1` a thousand times is
     * a thousand pairs and one leaf.
     */
    public function test_raw_pairs_are_counted_before_anything_deduplicates_them(): void
    {
        self::limits()->assertRawPairCount(FormLimits::MAX_INPUT_VARS);

        $this->expectException(FormLimitExceededException::class);
        $this->expectExceptionMessage('input variables');

        self::limits()->assertRawPairCount(FormLimits::MAX_INPUT_VARS + 1);
    }

    public function test_the_contract_sits_below_phps_own_defaults_so_the_contract_is_what_a_client_meets(): void
    {
        self::assertLessThan(1_000, FormLimits::MAX_INPUT_VARS, 'php default max_input_vars');
        self::assertLessThan(20, FormLimits::MAX_FILE_PARTS, 'php default max_file_uploads');
    }

    public function test_a_body_larger_than_the_ceiling_is_refused_on_its_actual_size(): void
    {
        $this->expectException(BodyTooLargeException::class);

        self::limits()->assertBodyWithinLimit(self::limits()->maxBodyBytes + 1, declaredBytes: null);
    }

    /**
     * The declared length is checked as well as the real one, so an
     * honestly-labeled oversized body is refused before anything spends
     * time on it — and a body that lies about being small still meets
     * the check above.
     */
    public function test_a_body_declaring_more_than_the_ceiling_is_refused_before_it_is_read(): void
    {
        $this->expectException(BodyTooLargeException::class);

        self::limits()->assertBodyWithinLimit(0, self::limits()->maxBodyBytes + 1);
    }

    public function test_a_body_at_the_ceiling_is_accepted(): void
    {
        self::limits()->assertBodyWithinLimit(self::limits()->maxBodyBytes, self::limits()->maxBodyBytes);

        $this->expectNotToPerformAssertions();
    }

    public function test_leaf_values_are_counted_however_deeply_they_nest(): void
    {
        $wide = ['a' => array_fill(0, FormLimits::MAX_INPUT_VARS, 'v'), 'b' => 'one too many'];

        $this->expectException(FormLimitExceededException::class);
        $this->expectExceptionMessage('maximum of ' . FormLimits::MAX_INPUT_VARS . ' input variables');

        self::limits()->assertFormWithinLimits($wide, []);
    }

    public function test_nesting_one_level_past_the_ceiling_is_refused(): void
    {
        $deep = 'leaf';

        for ($level = 0; $level < FormLimits::MAX_NESTING_DEPTH; $level++) {
            $deep = [$deep];
        }

        $this->expectException(FormLimitExceededException::class);
        $this->expectExceptionMessage('maximum nesting depth of ' . FormLimits::MAX_NESTING_DEPTH);

        self::limits()->assertFormWithinLimits(['a' => $deep], []);
    }

    public function test_nesting_exactly_at_the_ceiling_is_accepted(): void
    {
        $deep = 'leaf';

        for ($level = 1; $level < FormLimits::MAX_NESTING_DEPTH; $level++) {
            $deep = [$deep];
        }

        self::limits()->assertFormWithinLimits(['a' => $deep], []);

        $this->expectNotToPerformAssertions();
    }

    public function test_one_file_past_the_ceiling_is_refused(): void
    {
        $this->expectException(FormLimitExceededException::class);
        $this->expectExceptionMessage('maximum of ' . FormLimits::MAX_FILE_PARTS . ' file parts');

        self::limits()->assertFormWithinLimits([], array_fill(0, FormLimits::MAX_FILE_PARTS + 1, 'file'));
    }

    public function test_one_part_past_the_ceiling_is_refused(): void
    {
        self::limits()->assertMultipartPartCount(FormLimits::MAX_MULTIPART_PARTS);

        $this->expectException(FormLimitExceededException::class);
        $this->expectExceptionMessage('maximum of ' . FormLimits::MAX_MULTIPART_PARTS . ' multipart parts');

        self::limits()->assertMultipartPartCount(FormLimits::MAX_MULTIPART_PARTS + 1);
    }

    public function test_one_part_header_past_the_ceiling_is_refused(): void
    {
        self::limits()->assertPartHeaderCount(FormLimits::MAX_PART_HEADERS);

        $this->expectException(FormLimitExceededException::class);
        $this->expectExceptionMessage('maximum of ' . FormLimits::MAX_PART_HEADERS . ' headers');

        self::limits()->assertPartHeaderCount(FormLimits::MAX_PART_HEADERS + 1);
    }

    public function test_one_part_header_line_past_the_byte_ceiling_is_refused(): void
    {
        self::limits()->assertPartHeaderLength(FormLimits::MAX_PART_HEADER_BYTES);

        $this->expectException(FormLimitExceededException::class);
        $this->expectExceptionMessage('maximum of ' . FormLimits::MAX_PART_HEADER_BYTES . ' bytes');

        self::limits()->assertPartHeaderLength(FormLimits::MAX_PART_HEADER_BYTES + 1);
    }

    /**
     * The names are checked before `parse_str()` sees them, so a form
     * over a ceiling is refused rather than parsed into whatever that
     * function was willing to register.
     */
    public function test_names_are_held_to_the_contract_before_they_are_parsed(): void
    {
        $names = [];

        for ($i = 0; $i < FormLimits::MAX_INPUT_VARS; $i++) {
            $names[] = "field{$i}";
        }

        self::limits()->assertNamesParseable($names);
        self::limits()->assertNamesParseable(['a' . str_repeat('[b]', FormLimits::MAX_NESTING_DEPTH - 1)]);

        $names[] = 'csrf_token';

        $this->expectException(FormLimitExceededException::class);
        $this->expectExceptionMessage('input variables');

        self::limits()->assertNamesParseable($names);
    }

    public function test_a_name_one_level_past_the_ceiling_is_refused_before_it_is_parsed(): void
    {
        $this->expectException(FormLimitExceededException::class);
        $this->expectExceptionMessage('nesting depth');

        self::limits()->assertNamesParseable(['a' . str_repeat('[b]', FormLimits::MAX_NESTING_DEPTH)]);
    }

    /**
     * A name whose brackets do not close builds no nesting at all —
     * `parse_str()` registers it as one flat key, and it is measured as
     * the one level it becomes rather than the many it looks like.
     */
    public function test_a_name_whose_brackets_never_close_is_one_level(): void
    {
        self::limits()->assertNamesParseable(['a' . str_repeat('[b', FormLimits::MAX_NESTING_DEPTH * 4)]);

        $this->expectNotToPerformAssertions();
    }

    /**
     * Every refusal names a ceiling and a number. Nothing from the
     * request appears in one, which is what makes these messages safe to
     * return to the client verbatim — unlike a parse failure's, which
     * never is.
     */
    public function test_no_refusal_message_can_carry_anything_from_the_request(): void
    {
        $messages = [
            FormLimitExceededException::tooManyInputVariables(512)->getMessage(),
            FormLimitExceededException::tooManyFileParts(16)->getMessage(),
            FormLimitExceededException::tooDeeplyNested(8)->getMessage(),
            FormLimitExceededException::tooManyMultipartParts(512)->getMessage(),
            FormLimitExceededException::tooManyPartHeaders(16)->getMessage(),
            FormLimitExceededException::partHeaderLineTooLong(8_192)->getMessage(),
            FormLimitExceededException::sapiMayHaveTruncated('post_max_size', 2_048)->getMessage(),
        ];

        foreach ($messages as $message) {
            self::assertMatchesRegularExpression('/^[A-Za-z0-9 ,.\'_]+$/', $message, 'a limit message is a fixed sentence and a number');
        }
    }
}
