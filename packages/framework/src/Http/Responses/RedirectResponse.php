<?php

declare(strict_types=1);

namespace Kinetis\Http\Responses;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

final class RedirectResponse
{
    public static function to(string $url, int $status = 302): ResponseInterface
    {
        return new Response(
            status: $status,
            headers: ['Location' => $url],
        );
    }
}
