<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Responses;

use Kinetis\Http\Responses\Exception\FileResponseException;
use Kinetis\Http\Responses\FileResponse;
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
}
