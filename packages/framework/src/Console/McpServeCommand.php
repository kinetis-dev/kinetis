<?php

declare(strict_types=1);

namespace Kinetis\Console;

use Kinetis\Cache\CacheStore;
use Kinetis\Cache\Compiler;
use Kinetis\Console\Attributes\Command;
use Kinetis\Container\RequestScope;
use Kinetis\Mcp\McpDiscovery;
use Kinetis\Mcp\McpDispatcher;
use Kinetis\Mcp\McpRegistry;
use Kinetis\Mcp\McpServer;
use Kinetis\Mcp\Transport\StdioTransport;
use Kinetis\Runtime\AppEnvironment;
use Kinetis\Runtime\ProjectRoot;
use Psr\Log\LoggerInterface;

/**
 * Starts Kinetis's MCP server over stdio — a real #[Command] like any
 * other now, discovered the same way (living under Kinetis\Console) with
 * no special-casing left in bin/kinetis. A long-running server loop
 * rather than a one-shot action, but that's no different structurally
 * from any other command: it runs until stdin closes, then returns an
 * exit code like anything else. Running through the generic
 * command-dispatch path also means it now gets TransactionGuard's
 * dangling-transaction safety net for free — it never had that as a
 * hardcoded bin/kinetis branch.
 *
 * Constructor-injects the concrete RequestScope (never a generic
 * ContainerInterface — interfaces can't be autowired by reflection here)
 * — safe specifically because bin/kinetis always dispatches a command
 * through the request's own scope, never through AppScope directly.
 */
final readonly class McpServeCommand
{
    /**
     * $projectRootOverride, $input, and $output are optional,
     * appended-last constructor parameters for the same testability
     * reason BuildCommand's own override and RoutesListCommand's own
     * $output exist — their defaults cost nothing when the container
     * autowires this normally, per Autowire::instantiate()'s existing
     * tolerance for an unresolvable parameter with a default value.
     * Without injectable streams the command can only be run against the
     * real process stdin, which is to say not run at all.
     *
     * $input/$output are typed mixed rather than resource because a
     * readonly property needs a native type and PHP has none for a
     * resource — the same reason RoutesListCommand types its own.
     */
    public function __construct(
        private RequestScope $scope,
        private ?string $projectRootOverride = null,
        private mixed $input = STDIN,
        private mixed $output = STDOUT,
    ) {}

    #[Command('mcp:serve', description: 'Starts the MCP server over stdio')]
    public function serve(): int
    {
        // dirname(__DIR__) — see BuildCommand's own doc comment for why:
        // this file lives one level deeper than bin/kinetis does.
        $projectRoot = $this->projectRootOverride ?? ProjectRoot::detect(dirname(__DIR__));
        $env = AppEnvironment::detect();
        $store = new CacheStore($projectRoot . '/.kinetis-cache');
        $mcpCache = $env->isProduction() ? $store->loadMcp() : null;

        if ($mcpCache === null && $env->isProduction()) {
            $compiled = (new Compiler())->compileProject($projectRoot);
            $store->writeAll($compiled);
            $mcpCache = $compiled->mcp;
        }

        if ($mcpCache !== null) {
            $registry = McpRegistry::fromArray(['tools' => $mcpCache->mcpTools, 'resources' => $mcpCache->mcpResources]);
            $dispatcher = new McpDispatcher($this->scope, $mcpCache->mcpBindingPlans, $mcpCache->hydrationPlans);
        } else {
            $registry = McpDiscovery::discover($projectRoot);
            $dispatcher = new McpDispatcher($this->scope);
        }

        /** @var LoggerInterface $logger */
        $logger = $this->scope->get(LoggerInterface::class);

        $mcp = new McpServer($registry, $dispatcher, logger: $logger);

        // The command's own scope stays what the dispatcher falls back
        // to, but every message gets a fresh scope of its own — created
        // per line by the transport from the real AppScope, reachable
        // here because a command's scope self-registers its parent.
        (new StdioTransport())->run($mcp, $this->input, $this->output, $this->scope->appScope());

        return 0;
    }
}
