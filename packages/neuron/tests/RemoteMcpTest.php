<?php

declare(strict_types=1);

namespace Kinetis\Neuron\Tests;

use Kinetis\Neuron\NeuronHttpClient;
use Kinetis\Neuron\Tests\Fixtures\Remote\ServiceTokenMiddleware;
use Kinetis\RevoltHttpClient\Exception\HttpFailure;
use Kinetis\RevoltHttpClient\Exception\HttpRequestException;
use Kinetis\RevoltHttpClient\Http;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\MCP\McpConnector;
use NeuronAI\MCP\McpException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * A Neuron agent using the tools of a Kinetis application's `/mcp`
 * route, served by `php -S` from Fixtures/mcp-server, through Neuron's
 * StreamableHttpTransport over NeuronHttpClient.
 */
final class RemoteMcpTest extends TestCase
{
    private const string HOST = '127.0.0.1:8191';

    private const string ORIGIN = 'http://' . self::HOST;

    private const string URL = self::ORIGIN . '/mcp';

    /** @var resource|null */
    private static $server = null;

    private static string $log = '';

    public static function setUpBeforeClass(): void
    {
        self::$log = (string) tempnam(sys_get_temp_dir(), 'kinetis-mcp-log-');
        self::$server = proc_open(
            ['php', '-d', 'enable_post_data_reading=0', '-S', self::HOST, __DIR__ . '/Fixtures/mcp-server/index.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [...getenv(), 'PHP_CLI_SERVER_WORKERS' => '4', 'KINETIS_MCP_LOG' => self::$log],
        );

        $deadline = microtime(true) + 5.0;

        while (($socket = @stream_socket_client('tcp://' . self::HOST, timeout: 0.1)) === false) {
            if (microtime(true) > $deadline) {
                self::fail('The MCP server fixture never started accepting connections.');
            }

            usleep(20_000);
        }

        fclose($socket);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }

        @unlink(self::$log);
    }

    protected function setUp(): void
    {
        file_put_contents(self::$log, '');
    }

    public function test_an_agent_lists_the_remote_tools_calls_one_and_gets_its_result(): void
    {
        $provider = $this->answer(self::connector()->tools(), 'echo_text', ['text' => 'hello']);

        self::assertSame(
            ['echo_text', 'large_text', 'slow_text'],
            array_map(static fn (ToolInterface $tool): string => $tool->getName(), $provider->getRecorded()[0]->tools),
        );
        self::assertSame('"echo: hello"', self::toolResultSentBack($provider));
        self::assertSame(['initialize', 'notifications/initialized', 'tools/list', 'tools/call'], self::methods());
    }

    public function test_a_released_connector_ends_its_session_with_a_delete_carrying_the_session_id(): void
    {
        $this->answer(self::connector()->tools(), 'echo_text', ['text' => 'hello']);

        $session = self::requests()[1][1];

        self::assertNotSame('', $session);
        self::assertSame(
            [
                ['initialize', ''],
                ['notifications/initialized', $session],
                ['tools/list', $session],
                ['tools/call', $session],
                ['DELETE', $session],
            ],
            self::requests(),
        );
    }

    public function test_an_application_scoped_connector_shares_one_session_and_a_request_scoped_one_opens_and_ends_its_own(): void
    {
        $shared = self::connector();

        foreach (['first', 'second'] as $request) {
            $this->answer($shared->tools(), 'echo_text', ['text' => $request]);
        }

        self::assertSame(['initialize' => 1, 'tools/call' => 2, 'tools/list' => 2], self::counts());

        file_put_contents(self::$log, '');

        foreach (['first', 'second'] as $request) {
            $this->answer(self::connector()->tools(), 'echo_text', ['text' => $request]);
        }

        self::assertSame(['DELETE' => 2, 'initialize' => 2, 'tools/call' => 2, 'tools/list' => 2], self::counts());
    }

    public function test_a_result_over_the_byte_ceiling_fails_with_the_ceiling(): void
    {
        $connector = self::connector(new Http()->withTimeout(10)->withMaxResponseBytes(4096));

        $failure = $this->failure(fn () => $this->answer($connector->tools(), 'large_text', ['bytes' => 8192]));

        self::assertInstanceOf(McpException::class, $failure);
        self::assertSame(HttpFailure::ResponseTooLarge, self::category($failure));
    }

    public function test_the_connector_timeout_is_each_calls_deadline(): void
    {
        $connector = self::connector(config: ['timeout' => 0.5]);
        $tools = $connector->tools();
        $startedAt = microtime(true);

        $failure = $this->failure(fn () => $this->answer($tools, 'slow_text', ['milliseconds' => 3000]));

        self::assertInstanceOf(McpException::class, $failure);
        self::assertSame(HttpFailure::Timeout, self::category($failure));
        self::assertLessThan(2.0, microtime(true) - $startedAt);
    }

    public function test_a_server_url_outside_the_clients_origin_is_refused_before_anything_is_sent(): void
    {
        $client = new NeuronHttpClient(new Http()->withTimeout(10), 'http://127.0.0.1:8190');
        $connector = new McpConnector(['url' => self::URL, 'token' => ServiceTokenMiddleware::TOKEN], $client);

        $failure = $this->failure(static fn () => $connector->tools());

        self::assertInstanceOf(McpException::class, $failure);
        self::assertSame(HttpFailure::InvalidRequest, self::category($failure));
        self::assertSame([], self::methods());
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function connector(?Http $http = null, array $config = []): McpConnector
    {
        return new McpConnector(
            ['url' => self::URL, 'token' => ServiceTokenMiddleware::TOKEN, ...$config],
            new NeuronHttpClient($http ?? new Http()->withTimeout(10), self::ORIGIN),
        );
    }

    /**
     * One request's agent run: the model calls $tool with $arguments,
     * then answers.
     *
     * @param list<ToolInterface> $tools
     * @param array<string, mixed> $arguments
     */
    private function answer(array $tools, string $tool, array $arguments): FakeAIProvider
    {
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [new ToolCall($tool, 'call-1', $arguments)]),
            new AssistantMessage('done'),
        );

        Agent::make()
            ->setAiProvider($provider)
            ->setThreadId('thread-1')
            ->addTool($tools)
            ->chat(new UserMessage('Use the tool.'));

        return $provider;
    }

    private function failure(callable $call): Throwable
    {
        try {
            $call();
        } catch (Throwable $failure) {
            return $failure;
        }

        self::fail('The call was expected to throw.');
    }

    /** The category of the HttpRequestException behind $failure. */
    private static function category(Throwable $failure): ?HttpFailure
    {
        for ($cause = $failure; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof HttpRequestException) {
                return $cause->category;
            }
        }

        return null;
    }

    /** @return list<string> the JSON-RPC methods, or DELETE, the server received, in order */
    private static function methods(): array
    {
        return array_map(static fn (array $request): string => $request[0], self::requests());
    }

    /** @return list<array{string, string}> each request's JSON-RPC method, or DELETE, and its Mcp-Session-Id, in order */
    private static function requests(): array
    {
        return array_map(
            static fn (string $line): array => [strtok($line, ' '), (string) strtok('')],
            array_values(array_filter(explode("\n", (string) file_get_contents(self::$log)))),
        );
    }

    /** @return array<string, int> how often the server received each request method, or DELETE */
    private static function counts(): array
    {
        $counts = array_count_values(array_filter(self::methods(), static fn (string $method): bool => $method !== 'notifications/initialized'));
        ksort($counts);

        return $counts;
    }

    private static function toolResultSentBack(FakeAIProvider $provider): string
    {
        foreach ($provider->getRecorded()[1]->messages as $message) {
            if ($message instanceof ToolResultMessage) {
                return (string) $message->getToolCalls()[0]->getResult();
            }
        }

        self::fail('No tool result was sent back to the model.');
    }
}
