<?php

declare(strict_types=1);

// A Kinetis application serving its #[McpTool]s at POST /mcp, the way
// docs/mcp.md describes, for RemoteMcpTest to reach over real HTTP with
// `php -S`. The `mcp` group identifies a caller presenting
// ServiceTokenMiddleware::TOKEN. The fixture answers `initialize` with a
// new Mcp-Session-Id, which Kinetis itself never issues, so a client ends
// the session with DELETE /mcp; the router answers that 405. Before the
// kernel runs, each request's JSON-RPC method, or DELETE, and the
// Mcp-Session-Id it carries are appended as one line to the file
// KINETIS_MCP_LOG names.

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
$method = $_SERVER['REQUEST_METHOD'] === 'DELETE'
    ? 'DELETE'
    : (is_array($message) && is_string($message['method'] ?? null) ? $message['method'] : '-');
file_put_contents(
    (string) getenv('KINETIS_MCP_LOG'),
    trim($method . ' ' . ($_SERVER['HTTP_MCP_SESSION_ID'] ?? '')) . "\n",
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
    static fn (ServerRequestInterface $request): ResponseInterface => $method === 'initialize'
        ? $kernel->handle($request)->withHeader('Mcp-Session-Id', bin2hex(random_bytes(8)))
        : $kernel->handle($request),
);
