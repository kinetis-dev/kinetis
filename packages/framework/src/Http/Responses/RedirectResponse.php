<?php

declare(strict_types=1);

namespace Kinetis\Http\Responses;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

final class RedirectResponse
{
    /**
     * @param array<string, string> $headers
     */
    public static function to(string $url, int $status = 302, array $headers = []): ResponseInterface
    {
        return (new Response(
            status: $status,
            headers: $headers,
        ))->withHeader('Location', $url);
    }
}
