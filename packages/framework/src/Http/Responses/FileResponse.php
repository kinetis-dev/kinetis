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
            $headers['Content-Disposition'] = 'attachment; filename="' . $downloadFilename . '"';
        }

        return new Response(status: $status, headers: $headers, body: $contents);
    }

    private static function detectMimeType(string $contents): string
    {
        $detected = (new finfo(FILEINFO_MIME_TYPE))->buffer($contents);

        return $detected !== false ? $detected : 'application/octet-stream';
    }
}
