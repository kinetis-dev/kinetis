<?php

declare(strict_types=1);

namespace Kinetis\McpDocs\Exception;

use RuntimeException;

/**
 * A JSON-RPC 2.0 error this server answers a request with. Every code
 * it can produce has a named constructor here, so a call site cannot
 * invent one, and the message a client sees is written in one place per
 * code.
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
     * Valid JSON that is not a well-formed JSON-RPC 2.0 request object:
     * a missing or wrong `jsonrpc`, a missing or non-string `method`, an
     * `id` outside the string/int/null domain, or a top-level array —
     * batching, which this server does not implement.
     */
    public static function invalidRequest(): self
    {
        return new self('Invalid Request.', -32600);
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
     * The envelope an unexpected failure becomes. $message is written by
     * this server, never lifted from a caught exception: a fetch failure
     * carries a URL and a transport message that belong in this
     * process's own stderr diagnostics rather than in a protocol
     * response.
     */
    public static function internalError(string $message): self
    {
        return new self($message, -32603);
    }
}
