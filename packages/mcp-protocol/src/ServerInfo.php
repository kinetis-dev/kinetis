<?php

declare(strict_types=1);

namespace Kinetis\McpProtocol;

/**
 * The identity an MCP server answers `initialize` with, plus the optional
 * instructions a client shows its model. Immutable and set once at
 * construction: nothing about it depends on a message, a client or a
 * connection.
 */
final readonly class ServerInfo
{
    public function __construct(
        public string $name,
        public string $version,
        public ?string $instructions = null,
    ) {}
}
