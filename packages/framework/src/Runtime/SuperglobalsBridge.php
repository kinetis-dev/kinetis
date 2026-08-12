<?php

declare(strict_types=1);

namespace Kinetis\Runtime;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The one place superglobals and header()/http_response_code() are allowed
 * to appear. FpmAdapter and FrankenPhpAdapter both process a request using
 * $_SERVER/$_GET/php://input and emit a response the same way — the only
 * difference between them is what drives the loop around this — so the
 * conversion itself is shared here instead of duplicated in both adapters.
 */
final class SuperglobalsBridge
{
    public static function requestFromGlobals(): ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $creator = new ServerRequestCreator($factory, $factory, $factory, $factory);
        $request = $creator->fromGlobals();

        // PHP's SAPI only auto-populates $_POST/$_FILES — and so
        // getParsedBody()/getUploadedFiles() via fromGlobals() above —
        // for POST. A PUT/PATCH request with a multipart or url-encoded
        // body needs PHP 8.4's request_parse_body() instead. Verified
        // directly against a real PHP dev server handling a real PUT
        // multipart request, not assumed: calling this either before or
        // after fromGlobals() works with no conflict and no
        // double-consumption of php://input. fromArrays() never touches
        // php://input itself when $body is omitted, so rebuilding through
        // it here is safe regardless of what fromGlobals() already read.
        if ($request->getParsedBody() === null && self::isFormEncoded($request->getHeaderLine('Content-Type'))) {
            [$post, $files] = request_parse_body();

            $request = $creator->fromArrays(
                $_SERVER,
                ServerRequestCreator::getHeadersFromServer($_SERVER),
                $_COOKIE,
                $_GET,
                $post,
                $files,
            );
        }

        return $request;
    }

    private static function isFormEncoded(string $contentType): bool
    {
        return str_starts_with($contentType, 'multipart/form-data')
            || str_starts_with($contentType, 'application/x-www-form-urlencoded');
    }

    public static function emit(ResponseInterface $response): void
    {
        http_response_code($response->getStatusCode());

        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $index => $value) {
                header("{$name}: {$value}", $index === 0);
            }
        }

        // A StreamableResponseInterface's body is never read via getBody()
        // — its emitter writes+flushes output directly and incrementally,
        // since FrankenPHP/FPM both run this in a real per-request SAPI
        // context where flush() reaches the client immediately rather than
        // being buffered until the script ends.
        if ($response instanceof StreamableResponseInterface) {
            ($response->getEmitter())();

            return;
        }

        echo (string) $response->getBody();
    }
}
