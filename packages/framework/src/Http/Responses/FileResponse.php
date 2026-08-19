<?php

declare(strict_types=1);

namespace Kinetis\Http\Responses;

use Kinetis\Http\Responses\Exception\FileResponseException;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use finfo;

final class FileResponse
{
    public static function fromPath(string $path, int $status = 200, ?string $contentType = null, ?string $downloadFilename = null): ResponseInterface
    {
        if (!is_file($path)) {
            throw FileResponseException::fileNotFound($path);
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw FileResponseException::fileNotFound($path);
        }

        return self::fromContents($contents, $contentType ?? self::detectMimeType($contents), $status, $downloadFilename);
    }

    public static function fromContents(string $contents, string $contentType, int $status = 200, ?string $downloadFilename = null): ResponseInterface
    {
        $headers = [
            'Content-Type' => $contentType,
            'Content-Length' => (string) strlen($contents),
        ];

        if ($downloadFilename !== null) {
            $headers['Content-Disposition'] = self::contentDisposition($downloadFilename);
        }

        return new Response(status: $status, headers: $headers, body: $contents);
    }

    /**
     * Builds the header per RFC 6266. A download name often comes from
     * whatever a user called the file when they uploaded it, so it is
     * treated as untrusted: without escaping, a name containing a quote
     * and a semicolon closes the quoted-string and appends parameters of
     * its own, and `a.pdf"; filename="evil.exe` becomes a second
     * filename that decides what the browser saves.
     */
    private static function contentDisposition(string $filename): string
    {
        if ($filename === '') {
            throw FileResponseException::invalidDownloadFilename('is empty — pass null for no download name');
        }

        // Rejected here so the error names the filename. A control
        // character never reaches the header anyway: PSR-7 refuses one,
        // but as an opaque complaint about the header value.
        if (preg_match('/[\x00-\x1F\x7F]/', $filename) === 1) {
            throw FileResponseException::invalidDownloadFilename('contains a control character');
        }

        // The quoted-string carries the ASCII fallback, with \ and "
        // escaped as quoted-pairs so the name cannot end the quoting.
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $filename) ?? '';
        $disposition = 'attachment; filename="' . str_replace(['\\', '"'], ['\\\\', '\\"'], $ascii) . '"';

        // Anything outside ASCII travels percent-encoded as well, which
        // recipients that understand it prefer over the fallback.
        // rawurlencode() leaves only characters RFC 8187 allows bare.
        if ($ascii !== $filename) {
            $disposition .= "; filename*=UTF-8''" . rawurlencode($filename);
        }

        return $disposition;
    }

    private static function detectMimeType(string $contents): string
    {
        $detected = (new finfo(FILEINFO_MIME_TYPE))->buffer($contents);

        return $detected !== false ? $detected : 'application/octet-stream';
    }
}
