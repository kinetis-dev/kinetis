<?php

declare(strict_types=1);

namespace Kinetis\Neuron;

use Kinetis\Container\RequestScope;
use Kinetis\Mcp\KinetisMcpApplication;
use Kinetis\Mcp\McpDispatcher;
use Kinetis\Mcp\ToolDefinition;
use Kinetis\McpProtocol\ToolResult;
use Kinetis\Validation\Exception\ValidationException;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolOutput;
use stdClass;

/**
 * One `#[McpTool]` as a Neuron tool, bound to the request scope it was
 * built in.
 *
 * The provider receives the tool's MCP `inputSchema` unchanged, and the
 * tool declares no Neuron properties, so Neuron neither casts nor checks
 * the model's arguments: {@see McpDispatcher} validates them, exactly as
 * for an MCP client, and a failure returns the same `{"errors": [...]}`
 * feedback. Any other exception propagates and fails the run.
 *
 * @internal {@see McpTools::select()} builds every instance.
 */
final class ScopedMcpTool extends Tool
{
    public function __construct(
        private readonly ToolDefinition $definition,
        private readonly McpDispatcher $dispatcher,
        private readonly RequestScope $scope,
    ) {
        $this->name = $definition->name;
        $this->description = $definition->description;
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function getInputSchema(): array
    {
        return $this->definition->inputSchema;
    }

    #[\Override]
    public function execute(): void
    {
        try {
            $result = $this->dispatcher->callTool(
                $this->definition,
                KinetisMcpApplication::hydrationArguments(self::argumentsObject($this->inputs)),
                null,
                $this->scope,
            );
        } catch (ValidationException $e) {
            $this->setResult(ToolOutput::error(KinetisMcpApplication::validationFeedback($e)));

            return;
        }

        if ($result instanceof ToolResult) {
            $this->setResult($result->isError ? ToolOutput::error($result->text) : $result->text);

            return;
        }

        $this->setResult(json_encode($result, JSON_THROW_ON_ERROR));
    }

    /**
     * Neuron hands over the model's arguments decoded as PHP arrays; each
     * member is re-read as JSON so a nested object reaches the dispatcher
     * as one, as it does from an MCP client. The top level is an object
     * whatever its keys.
     *
     * @param array<array-key, mixed> $inputs
     */
    private static function argumentsObject(array $inputs): stdClass
    {
        $arguments = new stdClass();

        foreach ($inputs as $name => $value) {
            $arguments->{$name} = json_decode(
                json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                false,
                flags: JSON_THROW_ON_ERROR,
            );
        }

        return $arguments;
    }
}
