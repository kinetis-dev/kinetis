<?php

declare(strict_types=1);

namespace Kinetis\Http\Responses;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

final class HtmlResponse
{
    public static function create(string $html, int $status = 200): ResponseInterface
    {
        return new Response(
            status: $status,
            headers: ['Content-Type' => 'text/html; charset=utf-8'],
            body: $html,
        );
    }
}
