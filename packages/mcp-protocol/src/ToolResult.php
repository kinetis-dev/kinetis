<?php

declare(strict_types=1);

namespace Kinetis\McpProtocol;

/**
 * What a tool that ran concluded: one text content block, whether it is
 * a failure, and optionally the same conclusion as a JSON object.
 *
 * `isError` is the MCP convention for a tool that executed and refused or
 * failed — the model sees the reason and can correct its next call. A
 * malformed request, an unknown tool or an unexpected internal failure is
 * a JSON-RPC error instead; see {@see Exception\JsonRpcException}.
 *
 * `structuredContent` is the object a client that reads structured output
 * takes instead of parsing the text. The text block is sent either way,
 * because it is what every other client reads, so {@see structured()}
 * takes both from the caller that authored them rather than deriving one
 * from the other.
 */
final readonly class ToolResult
{
    /**
     * @param array<string, mixed>|null $structuredContent
     */
    private function __construct(
        public string $text,
        public bool $isError,
        public ?array $structuredContent,
    ) {}

    public static function text(string $text): self
    {
        return new self($text, false, null);
    }

    public static function error(string $text): self
    {
        return new self($text, true, null);
    }

    /**
     * A result whose text is the JSON encoding of $document, sent with
     * $document itself as `structuredContent`.
     *
     * @param array<string, mixed> $document sent as a JSON object, `{}`
     *        when empty
     */
    public static function structured(string $text, array $document, bool $isError): self
    {
        return new self($text, $isError, $document);
    }
}
