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
     * $projectRootOverride is accepted as an optional, appended-last
     * constructor parameter for the same testability reason
     * BuildCommand's own override exists — a default of null costs
     * nothing when the container autowires this normally, per
     * Autowire::instantiate()'s existing tolerance for an unresolvable
     * scalar parameter with a default value.
     */
    public function __construct(
        private RequestScope $scope,
        private ?string $projectRootOverride = null,
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

        (new StdioTransport())->run($mcp, STDIN, STDOUT);

        return 0;
    }
}
