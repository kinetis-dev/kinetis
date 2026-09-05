<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Form;

use Kinetis\Http\Form\Exception\FormLimitExceededException;
use Kinetis\Http\Form\Exception\UnparseableFormBodyException;
use Kinetis\Http\Form\FormLimits;
use Kinetis\Http\Form\MultipartEnvelope;
use PHPUnit\Framework\TestCase;

/**
 * The bounded scan every runtime runs over raw multipart bytes before
 * anything expands them. What it has to see is precisely what a parsed
 * result cannot show: parts that build nothing, and header lines that
 * collapse into one map entry.
 */
final class MultipartEnvelopeTest extends TestCase
{
    private const string BOUNDARY = 'B';

    private const string CONTENT_TYPE = 'multipart/form-data; boundary=B';

    private static function limits(): FormLimits
    {
        return new FormLimits(FormLimits::DEFAULT_MAX_BODY_BYTES);
    }

    /**
     * @param list<string> $parts each already a complete part body
     */
    private static function envelope(array $parts): string
    {
        $body = '';

        foreach ($parts as $part) {
            $body .= '--' . self::BOUNDARY . "\r\n" . $part . "\r\n";
        }

        return $body . '--' . self::BOUNDARY . "--\r\n";
    }

    public function test_fields_and_files_are_read_with_their_names_filenames_and_bodies(): void
    {
        $parts = MultipartEnvelope::parts(self::envelope([
            "Content-Disposition: form-data; name=\"name\"\r\n\r\nAlon",
            "Content-Disposition: form-data; name=\"avatar\"; filename=\"a.png\"\r\nContent-Type: image/png\r\n\r\npng bytes",
        ]), self::CONTENT_TYPE, self::limits());

        self::assertCount(2, $parts);
        self::assertSame('name', $parts[0]->name);
        self::assertNull($parts[0]->filename);
        self::assertFalse($parts[0]->isFile());
        self::assertSame('Alon', $parts[0]->body);

        self::assertSame('avatar', $parts[1]->name);
        self::assertSame('a.png', $parts[1]->filename);
        self::assertSame('image/png', $parts[1]->contentType);
        self::assertTrue($parts[1]->isFile());
        self::assertSame('png bytes', $parts[1]->body);
    }

    /**
     * A body containing the boundary bytes inside a part must not be
     * split there — only a real delimiter (CRLF, `--`, the boundary, end
     * of line) ends a part.
     */
    public function test_a_part_body_containing_the_boundary_text_is_not_split_on_it(): void
    {
        $parts = MultipartEnvelope::parts(self::envelope([
            "Content-Disposition: form-data; name=\"note\"\r\n\r\nmentions --B in passing",
        ]), self::CONTENT_TYPE, self::limits());

        self::assertSame('mentions --B in passing', $parts[0]->body);
    }

    public function test_a_part_declaring_an_empty_filename_keeps_it_distinct_from_none(): void
    {
        $parts = MultipartEnvelope::parts(self::envelope([
            "Content-Disposition: form-data; name=\"empty\"; filename=\"\"\r\n\r\n",
            "Content-Disposition: form-data; name=\"field\"\r\n\r\nvalue",
        ]), self::CONTENT_TYPE, self::limits());

        self::assertSame('', $parts[0]->filename, 'an empty filename is a file control, not a field');
        self::assertTrue($parts[0]->isFile());
        self::assertNull($parts[1]->filename);
        self::assertFalse($parts[1]->isFile());
    }

    /**
     * An unnamed part builds neither a field nor a file, so it appears
     * nowhere in the parsed result — and a ceiling counted from that
     * result cannot see it. It is still a part.
     */
    public function test_an_unnamed_part_is_counted_and_reported_with_a_null_name(): void
    {
        $parts = MultipartEnvelope::parts(self::envelope([
            "\r\npadding with no headers at all",
            "Content-Disposition: form-data; name=\"real\"\r\n\r\nvalue",
        ]), self::CONTENT_TYPE, self::limits());

        self::assertCount(2, $parts);
        self::assertNull($parts[0]->name);
        self::assertSame('real', $parts[1]->name);
    }

    public function test_unnamed_parts_are_refused_once_they_pass_the_part_ceiling(): void
    {
        $parts = array_fill(0, FormLimits::MAX_MULTIPART_PARTS + 1, "\r\npadding");

        $this->expectException(FormLimitExceededException::class);
        $this->expectExceptionMessage('multipart parts');

        MultipartEnvelope::assertWithinLimits(self::envelope($parts), self::CONTENT_TYPE, self::limits());
    }

    /**
     * Header lines, not distinct names: a part repeating one header has
     * a single entry in any parser's header map and as many lines as it
     * sent.
     */
    public function test_repeated_header_lines_are_counted_individually(): void
    {
        $headers = "Content-Disposition: form-data; name=\"f\"\r\n";

        for ($i = 0; $i < FormLimits::MAX_PART_HEADERS; $i++) {
            $headers .= "X-Pad: v\r\n";
        }

        $this->expectException(FormLimitExceededException::class);
        $this->expectExceptionMessage('headers');

        MultipartEnvelope::assertWithinLimits(self::envelope([$headers . "\r\nvalue"]), self::CONTENT_TYPE, self::limits());
    }

    /**
     * The whole point of scanning before parsing: the refusal happens
     * without a single part being materialized. Proven by the counting
     * entry point never building one at all — it returns nothing, so
     * there is nothing it could have expanded.
     */
    public function test_the_counting_entry_point_materializes_no_parts(): void
    {
        $body = self::envelope(array_fill(0, 8, "Content-Disposition: form-data; name=\"f\"\r\n\r\nvalue"));

        MultipartEnvelope::assertWithinLimits($body, self::CONTENT_TYPE, self::limits());
        self::assertCount(8, MultipartEnvelope::parts($body, self::CONTENT_TYPE, self::limits()));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function unreadableEnvelopes(): iterable
    {
        yield 'no boundary parameter' => ['multipart/form-data', 'body'];
        yield 'an empty quoted boundary parameter' => ['multipart/form-data; boundary=""', 'body'];
        yield 'a boundary that appears nowhere' => [self::CONTENT_TYPE, 'nothing here at all'];
        yield 'a last part that is never closed' => [self::CONTENT_TYPE, "--B\r\nContent-Disposition: form-data; name=\"f\"\r\n\r\nvalue"];
        yield 'a part whose headers never end' => [self::CONTENT_TYPE, "--B\r\nContent-Disposition: form-data; name=\"f\"\r\n--B--\r\n"];
        yield 'a header line with no name' => [self::CONTENT_TYPE, "--B\r\n: value\r\n\r\nv\r\n--B--\r\n"];
        yield 'a delimiter with trailing junk on its line' => [self::CONTENT_TYPE, "--Bjunk\r\n\r\nv\r\n--B--\r\n"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unreadableEnvelopes')]
    public function test_an_unreadable_envelope_is_refused_with_a_fixed_category(string $contentType, string $body): void
    {
        try {
            MultipartEnvelope::parts($body, $contentType, self::limits());

            self::fail('an envelope that cannot be read must be refused');
        } catch (UnparseableFormBodyException $e) {
            self::assertNotSame('', $e->category);
            self::assertStringNotContainsString($body, $e->getMessage(), 'no fragment of the body may reach the message');
        }
    }

    public function test_a_body_whose_only_delimiter_is_the_closing_one_has_no_parts(): void
    {
        $this->expectException(UnparseableFormBodyException::class);

        MultipartEnvelope::parts("--B--\r\n", self::CONTENT_TYPE, self::limits());
    }

    // --- The contract ---------------------------------------------------

    /**
     * A line that merely starts with the boundary token is not a
     * delimiter, and what follows it is the part's own bytes. Splitting
     * there would end the part early and hand a handler a shorter value
     * than the client sent.
     */
    public function test_a_line_that_only_begins_with_the_boundary_is_payload(): void
    {
        $parts = MultipartEnvelope::parts(
            "--B\r\nContent-Disposition: form-data; name=\"note\"\r\n\r\nfirst\r\n--Btra\r\nsecond\r\n--B--\r\n",
            self::CONTENT_TYPE,
            self::limits(),
        );

        self::assertSame("first\r\n--Btra\r\nsecond", $parts[0]->body);
    }

    /**
     * Each rule with the fixed category it is refused under, so a
     * category cannot quietly change into another one — an operator
     * triages on exactly this string, and it is the only thing an
     * adapter logs.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function bodiesOutsideTheContract(): iterable
    {
        yield 'transport padding after the boundary' => ["--B \r\nContent-Disposition: form-data; name=\"a\"\r\n\r\nv\r\n--B--\r\n", 'no-parts'];
        yield 'a boundary after a bare newline' => ["--B\r\nContent-Disposition: form-data; name=\"a\"\r\n\r\nv\n--B\r\nContent-Disposition: form-data; name=\"b\"\r\n\r\nw\r\n--B--\r\n", 'ambiguous-delimiter'];
        yield 'a stray CR before the delimiter CRLF' => ["--B\r\r\nContent-Disposition: form-data; name=\"a\"\r\n\r\nv\r\n--B--\r\n", 'ambiguous-delimiter'];
        yield 'a base64 transfer encoding' => ["--B\r\nContent-Disposition: form-data; name=\"a\"\r\nContent-Transfer-Encoding: base64\r\n\r\naGk=\r\n--B--\r\n", 'undecodable-part'];
        yield 'an 8bit transfer encoding' => ["--B\r\nContent-Disposition: form-data; name=\"a\"\r\nContent-Transfer-Encoding: 8bit\r\n\r\nv\r\n--B--\r\n", 'undecodable-part'];
        yield 'an encoded word' => ["--B\r\nContent-Disposition: form-data; name=\"=?utf-8?B?YWJj?=\"\r\n\r\nv\r\n--B--\r\n", 'undecodable-part'];
        yield 'an extended parameter' => ["--B\r\nContent-Disposition: form-data; name*=utf-8''a\r\n\r\nv\r\n--B--\r\n", 'undecodable-part'];
        yield 'an escape inside a quoted parameter' => ["--B\r\nContent-Disposition: form-data; name=\"a\\\"b\"\r\n\r\nv\r\n--B--\r\n", 'undecodable-part'];
        yield 'a quoted parameter padded with spaces' => ["--B\r\nContent-Disposition: form-data; name=\"  a  \"\r\n\r\nv\r\n--B--\r\n", 'undecodable-part'];
        yield 'one parameter given twice' => ["--B\r\nContent-Disposition: form-data; name=\"a\"; name=\"b\"\r\n\r\nv\r\n--B--\r\n", 'undecodable-part'];
        yield 'a nested multipart part' => ["--B\r\nContent-Disposition: form-data; name=\"a\"\r\nContent-Type: multipart/mixed; boundary=I\r\n\r\n--I\r\n\r\nx\r\n--I--\r\n\r\n--B--\r\n", 'nested-multipart'];
        yield 'a repeated Content-Disposition' => ["--B\r\nContent-Disposition: form-data; name=\"a\"\r\nContent-Disposition: form-data; name=\"b\"\r\n\r\nv\r\n--B--\r\n", 'unreadable-multipart'];
        yield 'a folded header line' => ["--B\r\nContent-Disposition: form-data;\r\n name=\"a\"\r\n\r\nv\r\n--B--\r\n", 'unreadable-multipart'];
        yield 'a control character in a header line' => ["--B\r\nX-Note: a\x01b\r\nContent-Disposition: form-data; name=\"a\"\r\n\r\nv\r\n--B--\r\n", 'unreadable-multipart'];
    }

    /**
     * The root `Content-Type` is parsed whole, so a header naming the
     * delimiter more than once — or naming it and then carrying syntax
     * no parameter grammar covers — is refused before any parser reads
     * it. Each of these resolves to a different boundary depending on
     * which parser is asked, and a body split at a different place is a
     * different form.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function ambiguousContentTypes(): iterable
    {
        yield 'the boundary given twice' => ['multipart/form-data; boundary=B; boundary=C'];
        yield 'the same boundary given twice' => ['multipart/form-data; boundary=B; boundary=B'];
        yield 'syntax trailing a quoted boundary' => ['multipart/form-data; boundary="B"junk'];
        yield 'syntax trailing a token boundary' => ['multipart/form-data; boundary=B"junk"'];
        yield 'a boundary with no value at all' => ['multipart/form-data; boundary='];
        yield 'a boundary spelled in another case' => ['multipart/form-data; Boundary=B'];
        yield 'a boundary padded inside its quotes' => ['multipart/form-data; boundary=" B "'];
        yield 'an extended boundary parameter' => ["multipart/form-data; boundary*=utf-8''B"];
        yield 'a parameter beside it that does not parse' => ['multipart/form-data; charset="utf-8; boundary=B'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ambiguousContentTypes')]
    public function test_a_content_type_naming_no_single_boundary_is_refused(string $contentType): void
    {
        $body = "--B\r\nContent-Disposition: form-data; name=\"a\"\r\n\r\nv\r\n--B--\r\n";

        try {
            MultipartEnvelope::parts($body, $contentType, self::limits());

            self::fail('a content type naming no single boundary must be refused');
        } catch (UnparseableFormBodyException $e) {
            self::assertSame('ambiguous-boundary', $e->category);
            self::assertStringNotContainsString($contentType, $e->getMessage(), 'no fragment of the header may reach the message');
        }
    }

    /**
     * A parameter beside the boundary is ordinary and stays ordinary:
     * one canonical `boundary` is what the contract asks for, not the
     * only parameter a client may send.
     */
    public function test_a_content_type_carrying_another_parameter_beside_the_boundary_is_read(): void
    {
        $parts = MultipartEnvelope::parts(
            self::envelope(["Content-Disposition: form-data; name=\"a\"\r\n\r\nv"]),
            'multipart/form-data; charset=utf-8; boundary="B"',
            self::limits(),
        );

        self::assertSame('v', $parts[0]->body);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bodiesOutsideTheContract')]
    public function test_a_body_outside_the_contract_is_refused_under_its_own_category(string $body, string $category): void
    {
        try {
            MultipartEnvelope::parts($body, self::CONTENT_TYPE, self::limits());

            self::fail('a body outside the contract must be refused');
        } catch (UnparseableFormBodyException $e) {
            self::assertSame($category, $e->category);
        }
    }

    /**
     * The counting entry point enforces the identical contract: a
     * satellite adapter calls it and hands the same bytes to its own
     * parser, so a rule the count skipped would be a rule that runtime
     * alone does not have.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bodiesOutsideTheContract')]
    public function test_the_counting_entry_point_refuses_the_same_bodies(string $body, string $category): void
    {
        try {
            MultipartEnvelope::assertWithinLimits($body, self::CONTENT_TYPE, self::limits());

            self::fail('a body outside the contract must be refused by the count as well as the parse');
        } catch (UnparseableFormBodyException $e) {
            self::assertSame($category, $e->category);
        }
    }

    /**
     * The two spellings that decode to the bytes they were given, and
     * the only two a part may declare.
     */
    public function test_an_identity_transfer_encoding_is_accepted_in_either_spelling(): void
    {
        foreach (['7bit', 'BINARY'] as $encoding) {
            $parts = MultipartEnvelope::parts(
                "--B\r\nContent-Disposition: form-data; name=\"a\"\r\nContent-Transfer-Encoding: {$encoding}\r\n\r\nv\r\n--B--\r\n",
                self::CONTENT_TYPE,
                self::limits(),
            );

            self::assertSame('v', $parts[0]->body);
        }
    }

    public function test_a_header_line_past_the_byte_ceiling_is_refused(): void
    {
        $line = 'X-Pad: ' . str_repeat('p', FormLimits::MAX_PART_HEADER_BYTES);

        $this->expectException(FormLimitExceededException::class);
        $this->expectExceptionMessage('header line');

        MultipartEnvelope::assertWithinLimits(
            self::envelope(["{$line}\r\nContent-Disposition: form-data; name=\"a\"\r\n\r\nv"]),
            self::CONTENT_TYPE,
            self::limits(),
        );
    }
}
