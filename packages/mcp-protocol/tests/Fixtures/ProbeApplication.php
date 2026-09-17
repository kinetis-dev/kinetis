<?php

declare(strict_types=1);

namespace Kinetis\McpProtocol\Tests\Fixtures;

use Kinetis\McpProtocol\Exception\JsonRpcException;
use Kinetis\McpProtocol\McpApplication;
use Kinetis\McpProtocol\ProgressEmitter;
use Kinetis\McpProtocol\ResourceDescription;
use Kinetis\McpProtocol\ResourceResult;
use Kinetis\McpProtocol\ToolAnnotations;
use Kinetis\McpProtocol\ToolDescription;
use Kinetis\McpProtocol\ToolResult;
use RuntimeException;
use stdClass;

/**
 * A consumer covering every outcome the server has to carry: a plain
 * tool, one that reports progress, one that refuses, one that throws a
 * protocol error, one that throws something unexpected, one that returns
 * bytes JSON cannot encode, one that raises a protocol error carrying
 * such bytes, an annotated tool, and resources that do the same.
 *
 * Every call records what it received, so a test can assert the exact
 * argument tree and context the server passed through.
 */
final class ProbeApplication implements McpApplication
{
    /** @var list<array{name: string, arguments: stdClass, context: ?object}> */
    public array $calls = [];

    /** @var list<array{uri: string, context: ?object}> */
    public array $reads = [];

    /**
     * @param list<ToolDescription>|null $tools
     * @param list<ResourceDescription>|null $resources
     */
    public function __construct(
        private readonly ?array $tools = null,
        private readonly ?array $resources = null,
    ) {}

    #[\Override]
    public function tools(): array
    {
        return $this->tools ?? [
            new ToolDescription('echo', 'Echoes its arguments.', [
                'type' => 'object',
                'properties' => new stdClass(),
                'additionalProperties' => false,
            ]),
            new ToolDescription('progress', 'Reports progress, then finishes.', []),
            new ToolDescription('refuse', 'Runs and refuses.', []),
            new ToolDescription('protocol_failure', 'Raises a protocol error.', []),
            new ToolDescription('explode', 'Throws something unexpected.', []),
            new ToolDescription('unencodable', 'Returns bytes JSON cannot carry.', []),
            new ToolDescription('unencodable_error', 'Raises a protocol error JSON cannot carry.', []),
            new ToolDescription('annotated', 'Carries annotations.', [], new ToolAnnotations(
                readOnly: false,
                destructive: true,
                idempotent: false,
                openWorld: false,
            )),
        ];
    }

    #[\Override]
    public function resources(): array
    {
        return $this->resources ?? [
            new ResourceDescription('probe://text', 'Text', 'A readable page.', 'text/markdown'),
            new ResourceDescription('probe://broken', 'Broken', 'A page that cannot be read.', 'text/markdown'),
            new ResourceDescription('probe://unencodable', 'Unencodable', 'A page JSON cannot carry.', 'text/markdown'),
        ];
    }

    #[\Override]
    public function callTool(
        string $name,
        stdClass $arguments,
        ProgressEmitter $progress,
        ?object $context,
    ): ToolResult {
        $this->calls[] = ['name' => $name, 'arguments' => $arguments, 'context' => $context];

        return match ($name) {
            'progress' => $this->reportProgress($progress),
            'refuse' => ToolResult::error('Refused.'),
            'protocol_failure' => throw JsonRpcException::invalidParams('The tool says these params are wrong.'),
            'explode' => throw new RuntimeException('A secret path: /etc/passwd'),
            // Valid PHP strings that json_encode() refuses: a consumer
            // chooses these bytes, and the protocol boundary has to
            // survive them.
            'unencodable' => ToolResult::text("\xFF secret-marker"),
            'unencodable_error' => throw JsonRpcException::invalidParams("\xFF secret-marker"),
            default => ToolResult::text(json_encode($arguments, JSON_THROW_ON_ERROR)),
        };
    }

    #[\Override]
    public function readResource(string $uri, ?object $context): ResourceResult
    {
        $this->reads[] = ['uri' => $uri, 'context' => $context];

        if ($uri === 'probe://broken') {
            throw JsonRpcException::internalError('Could not read "probe://broken".');
        }

        if ($uri === 'probe://unencodable') {
            return new ResourceResult($uri, 'text/markdown', "\xFF secret-marker");
        }

        return new ResourceResult($uri, 'text/markdown', "# Probe\n");
    }

    private function reportProgress(ProgressEmitter $progress): ToolResult
    {
        $progress->report(1);
        $progress->report(2, 10, 'halfway');

        return ToolResult::text('done');
    }
}
