<?php

declare(strict_types=1);

namespace Kinetis\McpProtocol;

/**
 * What a tool that ran concluded: one text content block, and whether it
 * is a failure.
 *
 * `isError` is the MCP convention for a tool that executed and refused or
 * failed — the model sees the reason and can correct its next call. A
 * malformed request, an unknown tool or an unexpected internal failure is
 * a JSON-RPC error instead; see {@see Exception\JsonRpcException}.
 */
final readonly class ToolResult
{
    private function __construct(
        public string $text,
        public bool $isError,
    ) {}

    public static function text(string $text): self
    {
        return new self($text, false);
    }

    public static function error(string $text): self
    {
        return new self($text, true);
    }
}
