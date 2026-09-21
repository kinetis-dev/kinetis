<?php

declare(strict_types=1);

namespace Kinetis\Http\Responses;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

final class JsonResponse
{
    /**
     * @param array<string, string> $headers
     */
    public static function create(mixed $data, int $status = 200, array $headers = []): ResponseInterface
    {
        return (new Response(
            status: $status,
            headers: $headers,
            body: json_encode($data, JSON_THROW_ON_ERROR),
        ))->withHeader('Content-Type', 'application/json');
    }
}
