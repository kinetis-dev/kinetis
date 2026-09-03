<?php

declare(strict_types=1);

namespace Kinetis\Http\Responses;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * The framework's own error-body shape (`{"error": "..."}`), used for
 * every error response it produces itself — an unmatched route, a
 * validation failure, a caught `HttpStatusExceptionInterface`, an
 * unhandled exception's production body — so `$message` is not
 * necessarily framework-controlled text: it can be a satellite package's
 * own exception message, or (via `HttpStatusExceptionInterface`)
 * something an application constructed itself. It must never be the
 * reason this call fails.
 */
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
            // JSON_INVALID_UTF8_SUBSTITUTE: $message can be arbitrary
            // text handed in from outside this class — an exception's own
            // message is never validated as UTF-8 anywhere upstream — so
            // malformed bytes there must degrade to a substitute
            // character, not an uncaught JsonException that would replace
            // this response's own declared $status with a 500 several
            // layers away from wherever the exception actually came from.
            body: json_encode(['error' => $message], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
        );
    }
}
