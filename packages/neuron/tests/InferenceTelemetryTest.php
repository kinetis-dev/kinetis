<?php

declare(strict_types=1);

namespace Kinetis\Neuron\Tests;

use Kinetis\Container\AppScope;
use Kinetis\Instrumentation\NullTelemetry;
use Kinetis\Instrumentation\Telemetry;
use Kinetis\Instrumentation\TelemetryInterface;
use Kinetis\Mcp\McpRegistry;
use Kinetis\Neuron\Bedrock\BedrockProvider;
use Kinetis\Neuron\InferenceTelemetry;
use Kinetis\Neuron\InlineReasoningProvider;
use Kinetis\Neuron\McpTools;
use Kinetis\Neuron\NeuronHttpClient;
use Kinetis\Neuron\Tests\Fixtures\Bedrock\RecordingBedrock;
use Kinetis\Neuron\Tests\Fixtures\SuspendingProvider;
use Kinetis\Neuron\Tests\Fixtures\Tools\Caller;
use Kinetis\Neuron\Tests\Fixtures\Tools\SupportToolController;
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;
use Kinetis\RevoltHttpClient\Http;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;
use Throwable;

use function Kinetis\Async\concurrently;

/**
 * Agent runs observed through a framework telemetry backend that
 * records the inference and tool-call hooks.
 */
final class InferenceTelemetryTest extends TestCase
{
    private const string PROMPT = 'prompt-sentinel-7f3a';

    private const string ANSWER = 'answer-sentinel-9c1e';

    /** @var list<array{string, list<mixed>}> */
    private array $calls = [];

    private int $nextToken = 0;

    protected function setUp(): void
    {
        $telemetry = $this->createStub(TelemetryInterface::class);
        $telemetry->method('inferenceStarted')->willReturnCallback(function (string $system, string $model): int {
            $token = ++$this->nextToken;
            $this->calls[] = ['inferenceStarted', [$system, $model, $token]];

            return $token;
        });
        $telemetry->method('inferenceEnded')->willReturnCallback(
            function (mixed $token, ?int $inputTokens, ?int $outputTokens, ?Throwable $failure): void {
                $this->calls[] = ['inferenceEnded', [$token, $inputTokens, $outputTokens, $failure]];
            },
        );
        $telemetry->method('toolCallStarted')->willReturnCallback(function (string $tool): null {
            $this->calls[] = ['toolCallStarted', [$tool]];

            return null;
        });

        Telemetry::global()->swap($telemetry);
    }

    protected function tearDown(): void
    {
        Telemetry::global()->swap(new NullTelemetry());
    }

    public function test_each_provider_call_is_one_pair_with_its_model_and_usage_and_a_tool_call_adds_no_second_tool_span(): void
    {
        $app = new AppScope();
        $registry = new McpRegistry();
        $registry->register(SupportToolController::class);
        $app->instance(McpRegistry::class, $registry);
        $app->boot();
        $scope = $app->createRequestScope();
        $scope->instance(Caller::class, new Caller('alice'));

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [new ToolCall('who_am_i', 'call-1')])->setUsage(new Usage(11, 3)),
            new AssistantMessage(self::ANSWER)->setUsage(new Usage(20, 5)),
        );

        InferenceTelemetry::observe(Agent::make())
            ->setAiProvider($provider)
            ->setThreadId('thread-1')
            ->addTool($scope->get(McpTools::class)->select('who_am_i'))
            ->chat(new UserMessage(self::PROMPT));

        self::assertSame(2, $provider->getCallCount());
        self::assertEquals([
            ['inferenceStarted', ['_OTHER', 'fake', 1]],
            ['inferenceEnded', [1, 11, 3, null]],
            ['toolCallStarted', ['who_am_i']],
            ['inferenceStarted', ['_OTHER', 'fake', 2]],
            ['inferenceEnded', [2, 20, 5, null]],
        ], $this->calls);

        $reported = json_encode($this->calls, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::PROMPT, $reported);
        self::assertStringNotContainsString(self::ANSWER, $reported);
    }

    public function test_an_answer_without_usage_ends_its_pair_without_token_counts(): void
    {
        InferenceTelemetry::observe(Agent::make())
            ->setAiProvider(new FakeAIProvider(new AssistantMessage('pong')))
            ->setThreadId('thread-1')
            ->chat(new UserMessage('ping'));

        self::assertEquals([
            ['inferenceStarted', ['_OTHER', 'fake', 1]],
            ['inferenceEnded', [1, null, null, null]],
        ], $this->calls);
    }

    public function test_a_provider_failure_ends_the_pair_with_that_failure(): void
    {
        $failure = self::failure(static function (): void {
            InferenceTelemetry::observe(Agent::make())
                ->setAiProvider(new FakeAIProvider())
                ->setThreadId('thread-1')
                ->chat(new UserMessage('ping'));
        });

        self::assertInstanceOf(ProviderException::class, $failure);
        self::assertEquals([
            ['inferenceStarted', ['_OTHER', 'fake', 1]],
            ['inferenceEnded', [1, null, null, $failure]],
        ], $this->calls);
    }

    public function test_a_known_provider_is_named_in_the_gen_ai_vocabulary(): void
    {
        // The client is pinned to another origin, so OpenAI's request is
        // refused before anything is sent.
        $client = new NeuronHttpClient(new Http(AmpHttpClientFactory::create()), 'http://127.0.0.1:9/v1');

        $failure = self::failure(static function () use ($client): void {
            InferenceTelemetry::observe(Agent::make())
                ->setAiProvider(new OpenAI('key', 'gpt-4o-mini', httpClient: $client))
                ->setThreadId('thread-1')
                ->chat(new UserMessage('ping'));
        });

        self::assertInstanceOf(HttpException::class, $failure);
        self::assertSame(['inferenceStarted', ['openai', 'gpt-4o-mini', 1]], $this->calls[0]);
        self::assertCount(2, $this->calls);
    }

    public function test_the_kinetis_bedrock_provider_is_named_aws_bedrock(): void
    {
        $bedrock = new RecordingBedrock(RecordingBedrock::json(['message' => 'Throttled'], 429));

        $failure = self::failure(static function () use ($bedrock): void {
            InferenceTelemetry::observe(Agent::make())
                ->setAiProvider(new BedrockProvider($bedrock->client(), 'amazon.nova-lite-v1:0'))
                ->setThreadId('thread-1')
                ->chat(new UserMessage('ping'));
        });

        self::assertInstanceOf(ProviderException::class, $failure);
        self::assertSame(['inferenceStarted', ['aws.bedrock', 'amazon.nova-lite-v1:0', 1]], $this->calls[0]);
    }

    public function test_a_provider_behind_inline_reasoning_is_named_by_the_provider_it_wraps(): void
    {
        $bedrock = new RecordingBedrock(RecordingBedrock::json(['message' => 'Throttled'], 429));
        $provider = new InlineReasoningProvider(new BedrockProvider($bedrock->client(), 'amazon.nova-lite-v1:0'));

        $failure = self::failure(static function () use ($provider): void {
            InferenceTelemetry::observe(Agent::make())
                ->setAiProvider($provider)
                ->setThreadId('thread-1')
                ->chat(new UserMessage('ping'));
        });

        self::assertInstanceOf(ProviderException::class, $failure);
        self::assertSame(['inferenceStarted', ['aws.bedrock', 'amazon.nova-lite-v1:0', 1]], $this->calls[0]);
    }

    public function test_a_stream_its_reader_stops_still_ends_its_pair(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('a long streamed answer'))->setStreamChunkSize(2);
        $run = InferenceTelemetry::observe(Agent::make())
            ->setAiProvider($provider)
            ->setThreadId('thread-1')
            ->stream(new UserMessage('ping'));

        foreach ($run as $chunk) {
            if ($chunk instanceof TextChunk) {
                break;
            }
        }

        self::assertSame([['inferenceStarted', ['_OTHER', 'fake', 1]]], $this->calls);

        // What a disconnected browser leaves behind: AgentStream returns
        // and the run's generator is released unfinished.
        unset($run);

        self::assertCount(2, $this->calls);
        [$hook, [$token, $inputTokens, $outputTokens, $failure]] = $this->calls[1];
        self::assertSame(['inferenceEnded', 1, null, null], [$hook, $token, $inputTokens, $outputTokens]);
        self::assertInstanceOf(WorkflowException::class, $failure);
    }

    public function test_concurrent_agents_in_one_worker_each_end_their_own_pair(): void
    {
        $run = static function (string $model, float $delay, int $inputTokens): void {
            InferenceTelemetry::observe(Agent::make())
                ->setAiProvider(new SuspendingProvider($model, $delay, new AssistantMessage('pong')->setUsage(new Usage($inputTokens, 1))))
                ->setThreadId("thread-{$model}")
                ->chat(new UserMessage('ping'));
        };

        // The slower call starts first and ends last.
        concurrently([
            static fn () => $run('slow', 0.05, 100),
            static fn () => $run('fast', 0.01, 200),
        ]);

        $started = [];
        $ended = [];

        foreach ($this->calls as [$hook, $arguments]) {
            if ($hook === 'inferenceStarted') {
                $started[$arguments[2]] = $arguments[1];
            } elseif ($hook === 'inferenceEnded') {
                $ended[] = [$started[$arguments[0]], $arguments[1]];
            }
        }

        self::assertSame([1 => 'slow', 2 => 'fast'], $started);
        self::assertSame([['fast', 200], ['slow', 100]], $ended);
    }

    private static function failure(callable $call): Throwable
    {
        try {
            $call();
        } catch (Throwable $failure) {
            return $failure;
        }

        self::fail('The agent run was expected to throw.');
    }
}
