<?php

declare(strict_types=1);

namespace Kinetis\Http\Responses;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

final class PlainTextResponse
{
    /**
     * @param array<string, string> $headers
     */
    public static function create(string $text, int $status = 200, array $headers = []): ResponseInterface
    {
        return (new Response(
            status: $status,
            headers: $headers,
            body: $text,
        ))->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }
}
