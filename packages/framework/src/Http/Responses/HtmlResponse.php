<?php

declare(strict_types=1);

namespace Kinetis\Http\Responses;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

final class HtmlResponse
{
    /**
     * @param array<string, string> $headers
     */
    public static function create(string $html, int $status = 200, array $headers = []): ResponseInterface
    {
        return (new Response(
            status: $status,
            headers: $headers,
            body: $html,
        ))->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
