<?php

declare(strict_types=1);

namespace Kinetis\Neuron\Tests\Fixtures\Remote;

use Kinetis\Mcp\Attributes\McpTool;

/** The tools the remote MCP server fixture publishes. */
final readonly class RemoteToolController
{
    #[McpTool(name: 'echo_text', description: 'Answer with the given text')]
    public function echoText(string $text): string
    {
        return "echo: {$text}";
    }

    #[McpTool(name: 'large_text', description: 'Answer with the given number of bytes')]
    public function largeText(int $bytes): string
    {
        return str_repeat('x', $bytes);
    }

    #[McpTool(name: 'slow_text', description: 'Answer after the given number of milliseconds')]
    public function slowText(int $milliseconds): string
    {
        usleep($milliseconds * 1000);

        return 'late';
    }
}
