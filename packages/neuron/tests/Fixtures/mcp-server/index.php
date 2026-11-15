<?php

declare(strict_types=1);

// A Kinetis application serving its #[McpTool]s at POST /mcp, the way
// docs/mcp.md describes, for RemoteMcpTest to reach over real HTTP with
// `php -S`. The `mcp` group identifies a caller presenting
// ServiceTokenMiddleware::TOKEN. Before the kernel runs, the JSON-RPC
// method of every message is appended to the file KINETIS_MCP_LOG names.

require __DIR__ . '/../../../vendor/autoload.php';

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\Kernel;
use Kinetis\Http\Routing\Router;
use Kinetis\Http\TrustedProxies;
use Kinetis\Mcp\Http\McpController;
use Kinetis\Mcp\Http\McpIdentityGuardMiddleware;
use Kinetis\Mcp\Http\McpOriginMiddleware;
use Kinetis\Mcp\KinetisMcpApplication;
use Kinetis\Mcp\McpDispatcher;
use Kinetis\Mcp\McpRegistry;
use Kinetis\McpProtocol\McpServer;
use Kinetis\McpProtocol\ServerInfo;
use Kinetis\Neuron\Tests\Fixtures\Remote\RemoteToolController;
use Kinetis\Neuron\Tests\Fixtures\Remote\ServiceTokenMiddleware;
use Kinetis\Runtime\RuntimeDetector;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

$message = json_decode((string) file_get_contents('php://input'), true);
file_put_contents(
    (string) getenv('KINETIS_MCP_LOG'),
    (is_array($message) && is_string($message['method'] ?? null) ? $message['method'] : '-') . "\n",
    FILE_APPEND,
);

$app = new AppScope();
$app->instance(Config::class, new Config([]));
$registry = new McpRegistry();
$registry->register(RemoteToolController::class);
$app->instance(McpServer::class, new McpServer(
    new ServerInfo('Kinetis', '1.0.0'),
    new KinetisMcpApplication($registry, new McpDispatcher($app)),
));
$app->boot();

$router = new Router();
$router->register(McpController::class);

$kernel = new Kernel($app, $router, middlewareGroups: [
    'mcp' => [McpOriginMiddleware::class, ServiceTokenMiddleware::class, McpIdentityGuardMiddleware::class],
]);

RuntimeDetector::detect(TrustedProxies::fromList([]))->run(
    static fn (ServerRequestInterface $request): ResponseInterface => $kernel->handle($request),
);
