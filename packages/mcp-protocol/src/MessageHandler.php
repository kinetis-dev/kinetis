<?php

declare(strict_types=1);

namespace Kinetis\McpProtocol;

use Closure;

/**
 * What a transport hands one decoded message to. {@see McpServer} is the
 * implementation that speaks MCP; an adapter needing a per-message unit of
 * work wraps one and implements this too, so the transport stays unaware
 * of any lifecycle policy.
 */
interface MessageHandler
{
    /**
     * Answers one structurally decoded JSON-RPC message, or null when
     * there is nothing to send — a notification, or a message this server
     * ignores.
     *
     * $emit sends one complete JSON-RPC notification message, and is
     * invoked synchronously before the returned response. A transport that
     * cannot carry a notification passes null.
     *
     * $context travels to the consumer untouched; see {@see
     * McpApplication}.
     *
     * @param array<string, mixed> $message
     * @param Closure(array<string, mixed>): void|null $emit
     * @return array<string, mixed>|null
     */
    public function handle(array $message, ?Closure $emit = null, ?object $context = null): ?array;
}
