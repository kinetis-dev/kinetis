<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Responses;

use Kinetis\Http\Responses\Exception\FileResponseException;
use Kinetis\Http\Responses\FileResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FileResponseTest extends TestCase
{
    public function test_from_contents_sets_content_type_and_length(): void
    {
        $response = FileResponse::fromContents('hello world', 'text/plain');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/plain', $response->getHeaderLine('Content-Type'));
        self::assertSame('11', $response->getHeaderLine('Content-Length'));
        self::assertSame('hello world', (string) $response->getBody());
        self::assertFalse($response->hasHeader('Content-Disposition'));
    }

    public function test_from_contents_sets_a_content_disposition_when_a_download_filename_is_given(): void
    {
        $response = FileResponse::fromContents('binary-ish', 'application/octet-stream', downloadFilename: 'report.bin');

        self::assertSame('attachment; filename="report.bin"', $response->getHeaderLine('Content-Disposition'));
    }

    public function test_from_path_detects_the_mime_type_when_none_is_given(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kinetis_file_response_test_');
        file_put_contents($path, 'plain text content');

        try {
            $response = FileResponse::fromPath($path);

            self::assertSame('text/plain', $response->getHeaderLine('Content-Type'));
            self::assertSame('plain text content', (string) $response->getBody());
        } finally {
            unlink($path);
        }
    }

    public function test_from_path_accepts_an_explicit_content_type_override(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kinetis_file_response_test_');
        file_put_contents($path, 'pretend-this-is-a-png');

        try {
            $response = FileResponse::fromPath($path, contentType: 'image/png');

            self::assertSame('image/png', $response->getHeaderLine('Content-Type'));
        } finally {
            unlink($path);
        }
    }

    public function test_from_path_throws_for_a_missing_file(): void
    {
        $this->expectException(FileResponseException::class);

        FileResponse::fromPath('/does/not/exist.bin');
    }

    /**
     * The name a user gave the file when uploading it reaches this
     * header, so it is treated as untrusted. Unescaped, the quote and
     * semicolon here would close the quoted-string and append a second
     * filename parameter — the one the browser would then save under.
     */
    public function test_a_filename_cannot_close_the_quoting_and_add_parameters(): void
    {
        $response = FileResponse::fromContents('x', 'application/pdf', downloadFilename: 'a.pdf"; filename="evil.exe');

        $disposition = $response->getHeaderLine('Content-Disposition');

        self::assertSame('attachment; filename="a.pdf\\"; filename=\\"evil.exe"', $disposition);
        // The header text does contain "filename=" twice, but the second
        // sits inside the quoted value. What proves it cannot become a
        // parameter is that no unescaped quote appears between the
        // delimiters: with the quoted-pairs removed, exactly the opening
        // and closing quotes are left.
        self::assertSame(2, substr_count(str_replace(['\\\\', '\\"'], '', $disposition), '"'));
    }

    public function test_a_backslash_in_a_filename_is_escaped_rather_than_read_as_an_escape(): void
    {
        $response = FileResponse::fromContents('x', 'application/pdf', downloadFilename: 'back\\slash.pdf');

        // Unescaped, \s is a quoted-pair meaning a literal "s", so the
        // name would silently arrive as backslash.pdf.
        self::assertSame('attachment; filename="back\\\\slash.pdf"', $response->getHeaderLine('Content-Disposition'));
    }

    /**
     * RFC 6266: an ASCII fallback for recipients that read only
     * `filename`, plus the RFC 8187 encoding they prefer when they
     * understand it.
     */
    public function test_a_non_ascii_filename_is_sent_both_ways(): void
    {
        $response = FileResponse::fromContents('x', 'application/pdf', downloadFilename: 'naïve-résumé.pdf');

        self::assertSame(
            'attachment; filename="na__ve-r__sum__.pdf"; filename*=UTF-8\'\'na%C3%AFve-r%C3%A9sum%C3%A9.pdf',
            $response->getHeaderLine('Content-Disposition'),
        );
    }

    /**
     * @return list<array{string}>
     */
    public static function unusableFilenames(): array
    {
        return [
            ["report\r\nX-Injected: 1.pdf"],
            ["null\x00byte.pdf"],
            ["tab\tseparated.pdf"],
            [''],
        ];
    }

    /**
     * PSR-7 refuses a control character in a header value anyway, but as
     * an opaque complaint about the value rather than the argument that
     * produced it.
     */
    #[DataProvider('unusableFilenames')]
    public function test_a_filename_that_cannot_be_sent_is_refused_by_name(string $filename): void
    {
        $this->expectException(FileResponseException::class);
        $this->expectExceptionMessage('download filename');

        FileResponse::fromContents('x', 'application/pdf', downloadFilename: $filename);
    }
}
