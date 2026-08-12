<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Transport;

use Kinetis\Mcp\McpServer;

/**
 * The transport `php kinetis mcp:serve` actually runs — one JSON-RPC
 * message per line on stdin, one response per line on stdout, matching
 * how Claude Desktop/Cursor and other local MCP clients launch a server
 * as a subprocess. Input/output streams are injectable (defaulting to
 * STDIN/STDOUT) so this is testable against php://memory instead of the
 * real process streams; the loop ends naturally at EOF either way — a
 * closed stdin in production, or the end of a fixed in-memory buffer in
 * tests.
 *
 * Progress notifications fall out of this transport for free: stdio is
 * already one-JSON-RPC-message-per-line, so a `notifications/progress`
 * message a tool call triggers mid-invocation is just one more line
 * written before the final response line — no separate streaming concept
 * needed here the way Kernel's HTTP endpoint needs one.
 */
final class StdioTransport
{
    /**
     * @param resource $input
     * @param resource $output
     */
    public function run(McpServer $server, $input, $output): void
    {
        while (($line = fgets($input)) !== false) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, associative: true);

            $onNotification = static function (array $notification) use ($output): void {
                fwrite($output, json_encode([
                    'jsonrpc' => '2.0',
                    'method' => 'notifications/progress',
                    'params' => $notification,
                ], JSON_THROW_ON_ERROR) . "\n");
            };

            $response = is_array($decoded)
                ? $server->handle($decoded, $onNotification)
                : ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error.']];

            if ($response !== null) {
                fwrite($output, json_encode($response, JSON_THROW_ON_ERROR) . "\n");
            }
        }
    }
}
