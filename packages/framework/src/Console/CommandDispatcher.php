<?php

declare(strict_types=1);

namespace Kinetis\Console;

use Psr\Container\ContainerInterface;

/**
 * Resolves a command's controller from the container and invokes it — the
 * console analogue of Http\Dispatcher/Mcp\McpDispatcher. Simpler than
 * either: CommandRegistry::register() already validated the method's
 * signature, so there's no per-call reflection needed here at all, only
 * a plain conditional call.
 *
 * The method's own return value becomes the process exit code: an `int`
 * is used directly, anything else (`void`/`null`) means success (`0`) —
 * the one thing genuinely different from HTTP/MCP dispatch, since a
 * command's exit code is the actual signal an external scheduler (cron,
 * a Kubernetes CronJob, ...) reads to decide whether to alert or retry.
 */
final class CommandDispatcher
{
    public function __construct(
        private readonly ContainerInterface $container,
    ) {}

    /**
     * @param list<string> $arguments
     */
    public function run(CommandDefinition $command, array $arguments): int
    {
        $controller = $this->container->get($command->controllerClass);

        $result = $command->takesArguments
            ? $controller->{$command->controllerMethod}(CommandArguments::parse($arguments))
            : $controller->{$command->controllerMethod}();

        return is_int($result) ? $result : 0;
    }
}
