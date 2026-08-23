<?php

declare(strict_types=1);

namespace Kinetis\Runtime;

use Kinetis\Http\Responses\ErrorResponse;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RequestParseBodyException;

/**
 * The one place superglobals and header()/http_response_code() are allowed
 * to appear. FpmAdapter and FrankenPhpAdapter both process a request using
 * $_SERVER/$_GET/php://input and emit a response the same way — the only
 * difference between them is what drives the loop around this — so the
 * conversion itself is shared here instead of duplicated in both adapters.
 */
final class SuperglobalsBridge
{
    /**
     * The full request/handle/emit cycle for one request, wrapping the
     * one failure mode that happens *before* $handler (and so before
     * Kernel/ExceptionHandlerMiddleware) ever runs: request_parse_body()
     * — called below for a PUT/PATCH form body — can throw
     * RequestParseBodyException for malformed input or an exceeded
     * parsing limit. Left uncaught, that's a fatal error escaping the
     * persistent request callback (FrankenPhpAdapter) or an unhandled
     * exception PHP itself renders (FpmAdapter) — neither is a JSON
     * error response, and neither goes through this framework's own
     * error-handling policy at all. Both adapters call this instead of
     * requestFromGlobals()+emit() directly, so the failure is caught in
     * exactly one place rather than duplicated in each.
     *
     * @param callable(ServerRequestInterface): ResponseInterface $handler
     */
    public static function handle(callable $handler): void
    {
        try {
            $request = self::requestFromGlobals();
        } catch (RequestParseBodyException $e) {
            // No structured way to distinguish "malformed" from "a
            // parsing limit was exceeded" on this exception — checked
            // directly against its real shape, not assumed: it carries
            // only a plain message and code, both undocumented. 400
            // either way; the real message is logged, never returned to
            // the client, since it may echo back a fragment of
            // attacker-controlled input.
            error_log('Malformed request body: ' . $e->getMessage());
            self::emit(ErrorResponse::create(400, RuntimeAdapterInterface::MALFORMED_BODY_MESSAGE));

            return;
        }

        self::emit($handler($request));
    }

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
