<?php

declare(strict_types=1);

namespace Kinetis\Neuron;

use InvalidArgumentException;
use Kinetis\Container\RequestScope;
use Kinetis\Mcp\McpDispatcher;
use Kinetis\Mcp\McpRegistry;
use NeuronAI\Tools\ToolInterface;

/**
 * The application's own `#[McpTool]` tools as Neuron tools, called in
 * process with the current request's scope. Resolve it from the request
 * scope, never the application scope: every tool it builds runs with the
 * identity and request-scoped services of the request that built it.
 *
 * {@link https://kinetis.dev/docs/neuron.html} states the contract.
 */
final readonly class McpTools
{
    public function __construct(
        private McpRegistry $registry,
        private RequestScope $scope,
    ) {}

    /**
     * The named tools, in the order given.
     *
     * @return list<ToolInterface>
     * @throws InvalidArgumentException for a name the registry does not carry
     */
    public function select(string $name, string ...$names): array
    {
        $dispatcher = new McpDispatcher($this->scope);
        $tools = [];

        foreach ([$name, ...$names] as $toolName) {
            $definition = $this->registry->findTool($toolName)
                ?? throw new InvalidArgumentException("No #[McpTool] is registered as \"{$toolName}\".");

            $tools[] = new ScopedMcpTool($definition, $dispatcher, $this->scope);
        }

        return $tools;
    }
}
