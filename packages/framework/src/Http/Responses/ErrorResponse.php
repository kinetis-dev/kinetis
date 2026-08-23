<?php

declare(strict_types=1);

namespace Kinetis\Http\Responses;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

final class ErrorResponse
{
    /**
     * @param array<string, string> $headers merged with the fixed
     *   Content-Type — appended last so no existing positional call
     *   shifts which argument lands where. Used by Kernel to attach the
     *   RFC 9110-required `Allow` header on a 405 response, alongside the
     *   existing JSON body.
     */
    public static function create(int $status, string $message, array $headers = []): ResponseInterface
    {
        return new Response(
            status: $status,
            headers: [...$headers, 'Content-Type' => 'application/json'],
            body: json_encode(['error' => $message], JSON_THROW_ON_ERROR),
        );
    }
}
