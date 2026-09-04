<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http;

use Kinetis\Http\MediaType;
use PHPUnit\Framework\TestCase;

final class MediaTypeTest extends TestCase
{
    public function test_the_bare_media_type_drops_parameters_and_surrounding_whitespace(): void
    {
        self::assertSame('multipart/form-data', MediaType::of("  multipart/form-data ; boundary=----XYZ\t"));
    }

    public function test_a_header_with_no_value_names_no_media_type(): void
    {
        self::assertSame('', MediaType::of(''));
        self::assertFalse(MediaType::isFormEncoded(''));
        self::assertFalse(MediaType::isMultipartFormData(''));
    }

    public function test_both_form_media_types_are_form_encoded(): void
    {
        self::assertTrue(MediaType::isFormEncoded('application/x-www-form-urlencoded'));
        self::assertTrue(MediaType::isFormEncoded('multipart/form-data; boundary=----XYZ'));
    }

    public function test_a_media_type_is_matched_whatever_case_it_is_spelled_in(): void
    {
        self::assertTrue(MediaType::isFormEncoded('Application/X-WWW-Form-Urlencoded; charset=UTF-8'));
        self::assertTrue(MediaType::isFormEncoded('MULTIPART/FORM-DATA; boundary=----XYZ'));
        self::assertTrue(MediaType::isMultipartFormData('Multipart/Form-Data; boundary=----XYZ'));
    }

    /**
     * The distinction a prefix comparison loses: a longer media type
     * that happens to start with a form one is a different media type,
     * and a client naming it is not sending a form body.
     */
    public function test_a_longer_media_type_starting_with_a_form_one_is_not_a_form_type(): void
    {
        self::assertFalse(MediaType::isFormEncoded('application/x-www-form-urlencodedevil'));
        self::assertFalse(MediaType::isFormEncoded('multipart/form-data-evil; boundary=----XYZ'));
        self::assertFalse(MediaType::isMultipartFormData('multipart/form-datax'));
    }

    /**
     * Two Content-Type headers reach getHeaderLine() as one comma-joined
     * value, which names no single media type and so is classified as
     * none — never as the first of the two.
     */
    public function test_a_comma_joined_pair_of_content_types_names_no_media_type(): void
    {
        self::assertFalse(MediaType::isFormEncoded('application/x-www-form-urlencoded, application/json'));
    }

    public function test_a_json_body_is_not_form_encoded(): void
    {
        self::assertFalse(MediaType::isFormEncoded('application/json'));
        self::assertFalse(MediaType::isMultipartFormData('application/x-www-form-urlencoded'));
    }
}
