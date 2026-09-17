<?php

declare(strict_types=1);

namespace Kinetis\McpProtocol\Exception;

use RuntimeException;

/**
 * A JSON-RPC 2.0 error this server answers a request with. Every code it
 * can produce has a named constructor, so a call site cannot invent one
 * and the message a client sees is written in one place per code.
 *
 * A protocol failure is not a tool failure: a tool that ran and refused
 * is an ordinary result carrying `isError: true`. Only a malformed
 * request, an unknown method, an unknown tool or resource name, or an
 * unexpected internal failure becomes one of these.
 */
final class JsonRpcException extends RuntimeException
{
    /**
     * @param array<string, mixed>|null $data
     */
    private function __construct(
        string $message,
        public readonly int $rpcCode,
        public readonly ?array $data = null,
    ) {
        parent::__construct($message);
    }

    public static function parseError(): self
    {
        return new self('Parse error.', -32700);
    }

    /**
     * Valid JSON that is not a well-formed JSON-RPC 2.0 message: a
     * missing or wrong `jsonrpc`, a missing or empty `method`, an `id`
     * outside the string/integer domain this revision admits, or a
     * top-level array — batching, which this server does not implement.
     *
     * $message replaces the default only where a transport has a more
     * precise reason of its own, which today is the Streamable HTTP
     * protocol-version header.
     */
    public static function invalidRequest(?string $message = null): self
    {
        return new self($message ?? 'Invalid Request.', -32600);
    }

    public static function methodNotFound(string $method): self
    {
        return new self("Method not found: \"{$method}\".", -32601);
    }

    public static function invalidParams(string $message): self
    {
        return new self($message, -32602);
    }

    /**
     * MCP's own code for a `resources/read` naming a URI the server does
     * not serve. The requested URI travels in `data`, where a client can
     * read it without parsing the message.
     */
    public static function resourceNotFound(string $uri): self
    {
        return new self("Resource not found: \"{$uri}\".", -32002, ['uri' => $uri]);
    }

    /**
     * The envelope an internal failure becomes. $message is written by
     * the server or by the consumer that raised it, never lifted from a
     * caught exception: that text can carry SQL, a filesystem path, a
     * URL or a credential, none of which belongs to a remote caller.
     */
    public static function internalError(string $message = 'Internal error.'): self
    {
        return new self($message, -32603);
    }
}
