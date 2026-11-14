<?php

declare(strict_types=1);

namespace Kinetis\Neuron\Tests;

use InvalidArgumentException;
use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\Mcp\KinetisMcpApplication;
use Kinetis\Mcp\McpDispatcher;
use Kinetis\Mcp\McpRegistry;
use Kinetis\McpProtocol\McpServer;
use Kinetis\McpProtocol\ServerInfo;
use Kinetis\Neuron\McpTools;
use Kinetis\Neuron\Tests\Fixtures\Tools\Caller;
use Kinetis\Neuron\Tests\Fixtures\Tools\SupportToolController;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic\ToolMapper as AnthropicToolMapper;
use NeuronAI\Providers\OpenAI\ToolMapper as OpenAIToolMapper;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Application `#[McpTool]` tools called by a Neuron agent in process,
 * through a real registry, dispatcher and request scope.
 */
final class McpToolsTest extends TestCase
{
    private McpRegistry $registry;

    private AppScope $app;

    protected function setUp(): void
    {
        $this->registry = new McpRegistry();
        $this->registry->register(SupportToolController::class);

        $this->app = new AppScope();
        $this->app->instance(McpRegistry::class, $this->registry);
        $this->app->boot();
    }

    public function test_a_tool_called_by_an_agent_runs_in_the_callers_request_scope(): void
    {
        foreach (['alice', 'bob'] as $name) {
            $scope = $this->requestScope();
            $scope->instance(Caller::class, new Caller($name));

            $provider = new FakeAIProvider(
                new ToolCallMessage(null, [new ToolCall('who_am_i', 'call-1')]),
                new AssistantMessage('done'),
            );

            Agent::make()
                ->setAiProvider($provider)
                ->setThreadId("thread-{$name}")
                ->addTool($scope->get(McpTools::class)->select('who_am_i'))
                ->chat(new UserMessage('Who am I?'));

            self::assertSame(json_encode($name), self::toolResultSentBack($provider));
        }
    }

    public function test_invalid_arguments_return_the_payload_an_mcp_client_receives(): void
    {
        $calls = [
            'file_ticket' => '{"ticket":{"title":"ab","contact":{"email":"not-an-email","fax":"1"},"tags":["ok",5],"priority":"urgent"},"force":true}',
            'find_orders' => '{"limit":0,"note":5,"minTotal":"many"}',
            'update_contact' => '{"contact":"someone@example.com"}',
            // An object where a list is declared: refused only while nested
            // objects stay marked as objects on the way to the dispatcher.
            'file_ticket ' => '{"ticket":{"title":"Broken","contact":{"email":"a@example.com"},"tags":{"first":"x"}}}',
        ];

        foreach ($calls as $key => $arguments) {
            $name = trim($key);
            $output = $this->execute($name, $arguments);
            $mcp = $this->mcpCall($name, $arguments);

            self::assertInstanceOf(ToolOutput::class, $output, $name);
            self::assertTrue($output->isError(), $name);
            self::assertTrue($mcp['isError'], $name);
            self::assertStringStartsWith('{"errors":[', $mcp['text']);
            self::assertSame($mcp['text'], $output->getText(), $name);
        }
    }

    public function test_a_returned_value_is_encoded_as_an_mcp_client_receives_it(): void
    {
        $calls = [
            'find_orders' => '{"customer":"c-1","note":null,"minTotal":5.0}',
            'file_ticket' => '{"ticket":{"title":"Broken","contact":{"email":"a@example.com"},"tags":["billing"],"priority":"high"}}',
            'update_contact' => '{"contact":null}',
            // Bound only when the nested object reaches the dispatcher marked as one.
            'label_order' => '{"labels":{"channel":"email","attempts":2}}',
        ];

        foreach ($calls as $name => $arguments) {
            $mcp = $this->mcpCall($name, $arguments);

            self::assertFalse($mcp['isError'], $name);
            self::assertSame($mcp['text'], $this->execute($name, $arguments), $name);
        }

        // An omitted argument takes the method's own default.
        self::assertSame(
            ['customer' => 'c-1', 'limit' => 10, 'note' => null, 'minTotal' => 0, 'openOnly' => false],
            json_decode((string) $this->execute('find_orders', '{"customer":"c-1"}'), true),
        );
    }

    /**
     * Neuron decodes the model's arguments as PHP arrays, so an empty JSON
     * object arrives as `[]` and is indistinguishable from an empty list;
     * docs/neuron.md states this limit.
     */
    public function test_an_empty_object_map_is_read_as_a_json_array(): void
    {
        $output = $this->execute('label_order', '{"labels":{}}');

        self::assertInstanceOf(ToolOutput::class, $output);
        self::assertTrue($output->isError());
        self::assertStringContainsString('"code":"not_a_json_object"', $output->getText());
        self::assertFalse($this->mcpCall('label_order', '{"labels":{}}')['isError']);
    }

    public function test_a_returned_tool_result_keeps_its_text_and_error_flag(): void
    {
        $output = $this->execute('refuse', '{}');

        self::assertInstanceOf(ToolOutput::class, $output);
        self::assertTrue($output->isError());
        self::assertSame('Refunds need a manager.', $output->getText());
    }

    public function test_any_other_failure_propagates_out_of_the_agent_run(): void
    {
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [new ToolCall('explode', 'call-1')]),
            new AssistantMessage('unreachable'),
        );

        $agent = Agent::make()
            ->setAiProvider($provider)
            ->setThreadId('thread-1')
            ->addTool(new McpTools($this->registry, $this->requestScope())->select('explode'));

        $failure = null;

        try {
            $agent->chat(new UserMessage('Go'));
        } catch (RuntimeException $e) {
            $failure = $e;
        }

        self::assertNotNull($failure, 'The tool failure was expected to end the run.');
        self::assertSame('database unavailable', $failure->getMessage());

        self::assertSame(1, $provider->getCallCount(), 'No tool result went back to the model.');
    }

    /** @return iterable<string, array{string}> */
    public static function tools(): iterable
    {
        yield 'scalars, a constraint, nullable and defaulted' => ['find_orders'];
        yield 'nested DTO, enum, typed list' => ['file_ticket'];
        yield 'nullable DTO' => ['update_contact'];
        yield 'object map' => ['label_order'];
        yield 'no parameters' => ['who_am_i'];
    }

    #[DataProvider('tools')]
    public function test_the_provider_receives_the_mcp_input_schema_unchanged(string $name): void
    {
        $tool = $this->select($name);
        $schema = json_encode($this->registry->findTool($name)?->inputSchema, JSON_THROW_ON_ERROR);

        $openAi = new OpenAIToolMapper()->map([$tool])[0]['function'];
        $anthropic = new AnthropicToolMapper()->map([$tool])[0];

        self::assertSame($name, $openAi['name']);
        self::assertSame($schema, json_encode($openAi['parameters'], JSON_THROW_ON_ERROR));
        self::assertSame($schema, json_encode($anthropic['input_schema'], JSON_THROW_ON_ERROR));
        self::assertSame([], $tool->getProperties(), 'Neuron must not cast arguments ahead of the dispatcher.');
    }

    public function test_the_published_schema_keeps_what_the_dispatcher_enforces(): void
    {
        $orders = $this->select('find_orders')->getInputSchema();
        $ticket = $this->select('file_ticket')->getInputSchema()['properties']['ticket'];

        self::assertFalse($orders['additionalProperties']);
        self::assertSame(0, $orders['properties']['limit']['exclusiveMinimum']);
        self::assertSame(['string', 'null'], $orders['properties']['note']['type']);
        self::assertSame(['customer'], $orders['required']);
        self::assertFalse($ticket['additionalProperties']);
        self::assertSame(3, $ticket['properties']['title']['minLength']);
        self::assertSame(['low', 'high'], $ticket['properties']['priority']['enum']);
        self::assertSame('email', $ticket['properties']['contact']['properties']['email']['format']);
        self::assertFalse($ticket['properties']['contact']['additionalProperties']);
        self::assertSame(['type' => 'string'], $ticket['properties']['tags']['items']);
        self::assertSame(['object', 'null'], $this->select('update_contact')->getInputSchema()['properties']['contact']['type']);
    }

    public function test_an_unknown_tool_name_is_refused_when_the_tools_are_built(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"find_order"');

        new McpTools($this->registry, $this->requestScope())->select('who_am_i', 'find_order');
    }

    private function requestScope(): RequestScope
    {
        return $this->app->createRequestScope();
    }

    private function select(string $name): ToolInterface
    {
        return new McpTools($this->registry, $this->requestScope())->select($name)[0];
    }

    /** Runs the tool the way Neuron's ToolNode does: bind the decoded arguments, then execute. */
    private function execute(string $name, string $arguments): string|ToolOutput
    {
        $tool = clone $this->select($name);
        $tool->setInputs(json_decode($arguments, true, flags: JSON_THROW_ON_ERROR));
        $tool->execute();

        return $tool->getResult();
    }

    /**
     * The same call through the MCP server, as a client sends it.
     *
     * @return array{text: string, isError: bool}
     */
    private function mcpCall(string $name, string $arguments): array
    {
        $server = new McpServer(
            new ServerInfo('Kinetis', '1.0.0'),
            new KinetisMcpApplication($this->registry, new McpDispatcher($this->app)),
        );

        $response = $server->handle((array) json_decode(
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"' . $name . '","arguments":' . $arguments . '}}',
            false,
            flags: JSON_THROW_ON_ERROR,
        ));

        self::assertIsArray($response);

        return ['text' => $response['result']['content'][0]['text'], 'isError' => $response['result']['isError']];
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
