<?php

declare(strict_types=1);

namespace Kinetis\DatabaseBridge\Tests;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\DatabaseBridge\PackageBootstrap;
use Kinetis\DatabaseBridge\Tests\Fixtures\DanglingTransactionController;
use Kinetis\DatabaseBridge\Tests\Fixtures\DanglingTransactionHolder;
use Kinetis\DatabaseBridge\Tests\Fixtures\DanglingTransactionToolController;
use Kinetis\Http\Kernel;
use Kinetis\Http\Routing\Router;
use Kinetis\Mcp\Http\McpController;
use Kinetis\Mcp\KinetisMcpApplication;
use Kinetis\Mcp\McpDispatcher;
use Kinetis\Mcp\McpRegistry;
use Kinetis\Mcp\ScopedMessageHandler;
use Kinetis\McpProtocol\McpServer;
use Kinetis\McpProtocol\ServerInfo;
use Kinetis\McpProtocol\StdioLoop;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * The TransactionGuard wiring this package's bootstrap installs, end to
 * end through the entry points that own a RequestScope per unit of work:
 * an HTTP request through Kernel, and an MCP message over HTTP and over
 * stdio. Each unit leaves a transaction open, and its scope's disposal
 * rolls it back — nothing in those entry points names the guard.
 */
final class KernelIntegrationTest extends TestCase
{
    public function test_rolls_back_a_transaction_left_open_by_the_controller(): void
    {
        $app = self::app();

        $router = new Router();
        $router->register(DanglingTransactionController::class);

        DanglingTransactionHolder::$link = null;

        $response = (new Kernel($app, $router))->handle(new ServerRequest('POST', '/begin-transaction'));

        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull(DanglingTransactionHolder::$link);
        self::assertTrue(DanglingTransactionHolder::$link->transactions[0]->rolledBack);
    }

    /**
     * An HTTP POST to /mcp is an ordinary Kernel request, so a tool
     * leaving a transaction open gets it rolled back exactly as an HTTP
     * controller does.
     */
    public function test_rolls_back_a_transaction_left_open_by_a_tool_over_http(): void
    {
        $app = self::app(static function (AppScope $app): void {
            $app->instance(Config::class, new Config([]));
            $registry = new McpRegistry();
            $registry->register(DanglingTransactionToolController::class);
            $app->instance(McpServer::class, new McpServer(
                new ServerInfo('Kinetis', '1.0.0'),
                new KinetisMcpApplication($registry, new McpDispatcher($app)),
            ));
        });

        $router = new Router();
        $router->register(McpController::class);
        $kernel = new Kernel($app, $router, middlewareGroups: ['mcp' => []]);

        DanglingTransactionHolder::$link = null;

        $request = new ServerRequest(
            'POST',
            '/mcp',
            [
                'Content-Type' => 'application/json',
                'MCP-Protocol-Version' => McpServer::PROTOCOL_VERSION,
            ],
        );
        $request->getBody()->write((string) \json_encode(self::toolCall()));
        $request->getBody()->rewind();

        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());

        // A 200 alone doesn't prove the tool actually ran — a rejected
        // envelope is answered with a JSON-RPC error under the same
        // status, so the envelope itself has to be checked too: a genuine
        // result, never an error, and the tool's own real return value
        // inside it.
        $body = \json_decode((string) $response->getBody(), true);
        self::assertArrayNotHasKey('error', $body);
        self::assertFalse($body['result']['isError']);
        self::assertSame(
            ['started' => true],
            \json_decode($body['result']['content'][0]['text'], true),
        );

        self::assertNotNull(DanglingTransactionHolder::$link);
        self::assertTrue(DanglingTransactionHolder::$link->transactions[0]->rolledBack);
    }

    public function test_rolls_back_a_transaction_left_open_by_a_tool_over_stdio(): void
    {
        $app = self::app();

        $registry = new McpRegistry();
        $registry->register(DanglingTransactionToolController::class);
        $server = new McpServer(
            new ServerInfo('Kinetis', '1.0.0'),
            new KinetisMcpApplication($registry, new McpDispatcher($app)),
        );

        DanglingTransactionHolder::$link = null;

        $input = \fopen('php://memory', 'r+');
        \assert($input !== false);
        \fwrite($input, (string) \json_encode(self::toolCall()) . "\n");
        \rewind($input);
        $output = \fopen('php://memory', 'r+');
        \assert($output !== false);

        new StdioLoop()->run(new ScopedMessageHandler($server, $app), $input, $output);

        // Decode and assert the actual output line rather than ignoring
        // it — a pre-dispatch rejection would still leave $output
        // non-empty (a JSON-RPC error line), so only inspecting it can
        // tell that apart from a genuine successful dispatch.
        \rewind($output);
        $written = (string) \stream_get_contents($output);
        $lines = \array_values(\array_filter(\explode("\n", $written)));
        self::assertCount(1, $lines);

        $response = \json_decode($lines[0], true);
        self::assertArrayNotHasKey('error', $response);
        self::assertFalse($response['result']['isError']);
        self::assertSame(
            ['started' => true],
            \json_decode($response['result']['content'][0]['text'], true),
        );

        self::assertNotNull(DanglingTransactionHolder::$link);
        self::assertTrue(DanglingTransactionHolder::$link->transactions[0]->rolledBack);
    }

    /**
     * @param (callable(AppScope): void)|null $beforeBoot registrations must
     *        happen before boot() locks the container
     */
    private static function app(?callable $beforeBoot = null): AppScope
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config([]));

        if ($beforeBoot !== null) {
            $beforeBoot($app);
        }

        $app->boot();

        return $app;
    }

    /** @return array<string, mixed> */
    private static function toolCall(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'begin_transaction'],
        ];
    }
}
