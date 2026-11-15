<?php

declare(strict_types=1);

namespace Kinetis\Neuron\Tests\Bedrock;

use Kinetis\AwsSigV4\Exception\NetworkFailureException;
use Kinetis\Neuron\Bedrock\BedrockProvider;
use Kinetis\Neuron\Bedrock\EventStreamDecoder;
use Kinetis\Neuron\NeuronHttpClient;
use Kinetis\Neuron\Tests\Fixtures\Bedrock\EventStreamFrame;
use Kinetis\Neuron\Tests\Fixtures\Bedrock\LookupOrder;
use Kinetis\Neuron\Tests\Fixtures\Bedrock\RecordingBedrock;
use Kinetis\Neuron\Tests\Fixtures\Bedrock\StaticCredentials;
use Kinetis\RevoltHttpClient\Http;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolArgumentChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\AWS\BedrockRuntime;
use NeuronAI\Providers\ProviderResponse;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The provider's requests and answer shaping, against a transport that
 * answers in process and records what it was sent.
 */
final class BedrockProviderTest extends TestCase
{
    private const string HAIKU = 'anthropic.claude-3-haiku-20240307-v1:0';

    private const array ANSWER = [
        'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'pong']]]],
        'stopReason' => 'end_turn',
        'usage' => ['inputTokens' => 3, 'outputTokens' => 1, 'cacheReadInputTokens' => 5, 'cacheWriteInputTokens' => 7],
    ];

    public function test_converse_is_a_signed_post_to_the_encoded_model_path(): void
    {
        $bedrock = new RecordingBedrock(RecordingBedrock::json(self::ANSWER));

        new BedrockProvider($bedrock->client(), self::HAIKU)->chat(new UserMessage('ping'));

        self::assertSame('POST', $bedrock->requests[0]['method']);
        self::assertSame(
            RecordingBedrock::ORIGIN . '/model/anthropic.claude-3-haiku-20240307-v1%3A0/converse',
            $bedrock->requests[0]['url'],
        );
        self::assertSame('application/json', $bedrock->header('Content-Type'));
        self::assertSame('application/json', $bedrock->header('Accept'));
        self::assertMatchesRegularExpression(
            '{^AWS4-HMAC-SHA256 Credential=' . StaticCredentials::ACCESS_KEY . '/\d{8}/us-east-1/bedrock/aws4_request, }',
            $bedrock->header('Authorization'),
        );
    }

    public function test_an_inference_profile_arn_is_sent_with_its_slash_encoded(): void
    {
        $bedrock = new RecordingBedrock(RecordingBedrock::frames(EventStreamFrame::event('messageStop', ['stopReason' => 'end_turn'])));
        $arn = 'arn:aws:bedrock:us-east-1:123456789012:inference-profile/us.' . self::HAIKU;

        $stream = new BedrockProvider($bedrock->client(), $arn)->stream(new UserMessage('ping'));
        iterator_to_array($stream);

        self::assertSame(
            RecordingBedrock::ORIGIN . '/model/arn%3Aaws%3Abedrock%3Aus-east-1%3A123456789012%3Ainference-profile%2Fus.'
                . 'anthropic.claude-3-haiku-20240307-v1%3A0/converse-stream',
            $bedrock->requests[0]['url'],
        );
        self::assertSame('application/vnd.amazon.eventstream', $bedrock->header('Accept'));
    }

    public function test_the_body_is_what_neurons_bedrock_runtime_builds_without_the_model_id(): void
    {
        $bedrock = new RecordingBedrock(RecordingBedrock::json(self::ANSWER));
        $inferenceConfig = ['maxTokens' => 256, 'temperature' => 0.2];
        $tools = [LookupOrder::make()];
        $messages = self::conversation();

        new BedrockProvider($bedrock->client(), self::HAIKU, $inferenceConfig)
            ->systemPrompt('Answer briefly.')
            ->setTools($tools)
            ->chat(...$messages);

        $expected = self::neuronPayload(self::HAIKU, $inferenceConfig, 'Answer briefly.', $tools, self::conversation());
        unset($expected['modelId']);

        self::assertSame(
            json_decode(json_encode($expected, JSON_THROW_ON_ERROR), true),
            $bedrock->body(),
        );
        self::assertCount(4, array_filter(
            array_merge(...array_column($bedrock->body()['messages'], 'content')),
            static fn (array $block): bool => isset($block['cachePoint']),
        ));
    }

    public function test_blob_members_travel_base64_encoded(): void
    {
        $bedrock = new RecordingBedrock(RecordingBedrock::json([
            'output' => ['message' => ['role' => 'assistant', 'content' => [
                ['reasoningContent' => ['redactedContent' => base64_encode("\x00\xffsecret")]],
                ['text' => 'pong'],
            ]]],
            'stopReason' => 'end_turn',
        ]), RecordingBedrock::json(self::ANSWER));
        $provider = new BedrockProvider($bedrock->client(), self::HAIKU);
        $question = new UserMessage([new TextContent('What is this?'), new ImageContent(base64_encode("\x89PNG"), SourceType::BASE64, 'image/png')]);

        $answer = $provider->chat($question)->message();

        self::assertSame(
            ['image' => ['format' => 'png', 'source' => ['bytes' => base64_encode("\x89PNG")]]],
            $bedrock->body()['messages'][0]['content'][1],
        );
        self::assertSame([0 => base64_encode("\x00\xffsecret")], $answer->getMetadata('aws_redacted_reasoning'));

        $provider->chat($question, $answer, new UserMessage('And now?'));

        self::assertSame(
            ['reasoningContent' => ['redactedContent' => base64_encode("\x00\xffsecret")]],
            $bedrock->body(1)['messages'][1]['content'][0],
        );
    }

    public function test_a_converse_answer_carries_its_text_usage_and_stop_reason(): void
    {
        $bedrock = new RecordingBedrock(RecordingBedrock::json(self::ANSWER));

        $message = new BedrockProvider($bedrock->client(), self::HAIKU)->chat(new UserMessage('ping'))->message();

        self::assertInstanceOf(AssistantMessage::class, $message);
        self::assertSame('pong', $message->getContent());
        self::assertSame('end_turn', $message->stopReason());
        self::assertSame(3 + 5 + 7, $message->getUsage()?->inputTokens);
        self::assertSame(1, $message->getUsage()?->outputTokens);
    }

    public function test_a_tool_use_answer_is_a_tool_call_message(): void
    {
        $bedrock = new RecordingBedrock(RecordingBedrock::json([
            'output' => ['message' => ['role' => 'assistant', 'content' => [
                ['text' => 'Looking it up.'],
                ['toolUse' => ['toolUseId' => 'tooluse_1', 'name' => 'lookup_order', 'input' => ['id' => 'A-7']]],
            ]]],
            'stopReason' => 'tool_use',
        ]));

        $message = new BedrockProvider($bedrock->client(), self::HAIKU)
            ->setTools([LookupOrder::make()])
            ->chat(new UserMessage('Where is A-7?'))
            ->message();

        self::assertInstanceOf(ToolCallMessage::class, $message);
        $call = $message->getToolCalls()[0];
        self::assertSame(['lookup_order', 'tooluse_1', ['id' => 'A-7']], [$call->getName(), $call->getCallId(), $call->getInputs()]);
        self::assertSame([1], $message->getMetadata('aws_tool_positions'));
    }

    public function test_a_streamed_tool_use_is_a_tool_call_message(): void
    {
        $bedrock = new RecordingBedrock(RecordingBedrock::frames(
            EventStreamFrame::event('messageStart', ['role' => 'assistant']),
            EventStreamFrame::event('contentBlockStart', ['contentBlockIndex' => 0, 'start' => ['toolUse' => ['toolUseId' => 'tooluse_1', 'name' => 'lookup_order']]]),
            EventStreamFrame::event('contentBlockDelta', ['contentBlockIndex' => 0, 'delta' => ['toolUse' => ['input' => '{"id":']]]),
            EventStreamFrame::event('contentBlockDelta', ['contentBlockIndex' => 0, 'delta' => ['toolUse' => ['input' => '"A-7"}']]]),
            EventStreamFrame::event('contentBlockStop', ['contentBlockIndex' => 0]),
            EventStreamFrame::event('messageStop', ['stopReason' => 'tool_use']),
            EventStreamFrame::event('metadata', ['usage' => ['inputTokens' => 9, 'outputTokens' => 4]]),
        ));

        $stream = new BedrockProvider($bedrock->client(), self::HAIKU)
            ->setTools([LookupOrder::make()])
            ->stream(new UserMessage('Where is A-7?'));
        $arguments = '';

        foreach ($stream as $chunk) {
            self::assertInstanceOf(ToolArgumentChunk::class, $chunk);
            $arguments .= $chunk->delta;
        }

        $message = $stream->getReturn()->message();

        self::assertSame('{"id":"A-7"}', $arguments);
        self::assertInstanceOf(ToolCallMessage::class, $message);
        self::assertSame(['id' => 'A-7'], $message->getToolCalls()[0]->getInputs());
        self::assertSame(9, $message->getUsage()?->inputTokens);
    }

    public function test_a_stream_reads_reasoning_and_redacted_reasoning(): void
    {
        $bedrock = new RecordingBedrock(RecordingBedrock::frames(
            EventStreamFrame::event('contentBlockDelta', ['contentBlockIndex' => 0, 'delta' => ['reasoningContent' => ['text' => 'Think']]]),
            EventStreamFrame::event('contentBlockDelta', ['contentBlockIndex' => 0, 'delta' => ['reasoningContent' => ['signature' => 'sig']]]),
            EventStreamFrame::event('contentBlockDelta', ['contentBlockIndex' => 1, 'delta' => ['reasoningContent' => ['redactedContent' => base64_encode('ab')]]]),
            EventStreamFrame::event('contentBlockDelta', ['contentBlockIndex' => 1, 'delta' => ['reasoningContent' => ['redactedContent' => base64_encode('cd')]]]),
            EventStreamFrame::event('contentBlockDelta', ['contentBlockIndex' => 2, 'delta' => ['text' => 'Done']]),
            EventStreamFrame::event('messageStop', ['stopReason' => 'end_turn']),
        ));

        $stream = new BedrockProvider($bedrock->client(), self::HAIKU)->stream(new UserMessage('ping'));
        $chunks = array_map(static fn (object $chunk): string => $chunk::class . ':' . $chunk->content, iterator_to_array($stream, false));
        $message = $stream->getReturn()->message();

        self::assertSame(['NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk:Think', TextChunk::class . ':Done'], $chunks);
        self::assertSame([1 => base64_encode('abcd')], $message->getMetadata('aws_redacted_reasoning'));
        self::assertSame('sig', $message->getContentBlocks()[0]->id);
    }

    public function test_structured_output_appends_the_schema_to_the_system_prompt_for_one_call(): void
    {
        $bedrock = new RecordingBedrock(
            RecordingBedrock::json(self::ANSWER),
            RecordingBedrock::json(self::ANSWER),
        );
        $schema = ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]];
        $provider = new BedrockProvider($bedrock->client(), self::HAIKU)->systemPrompt('Be brief.');

        $provider->structured(new UserMessage('Where?'), \stdClass::class, $schema);
        $provider->chat(new UserMessage('Again?'));

        self::assertSame(
            [['text' => 'Be brief.' . PHP_EOL . '# OUTPUT CONSTRAINTS' . PHP_EOL
                . 'Your response should be a JSON string following this schema: ' . PHP_EOL . json_encode($schema)]],
            $bedrock->body()['system'],
        );
        self::assertSame([['text' => 'Be brief.']], $bedrock->body(1)['system']);
    }

    public function test_an_error_status_carries_the_status_and_the_bedrock_message(): void
    {
        $bedrock = new RecordingBedrock(RecordingBedrock::json(['message' => 'The provided model identifier is invalid.'], 400));

        try {
            new BedrockProvider($bedrock->client(), self::HAIKU)->chat(new UserMessage('ping'));
            self::fail('A 400 was answered as a message.');
        } catch (ProviderException $e) {
            self::assertSame('Bedrock answered HTTP 400: The provided model identifier is invalid.', $e->getMessage());
            self::assertStringNotContainsString(StaticCredentials::ACCESS_KEY, $e->getMessage());
        }
    }

    public function test_a_transport_failure_keeps_the_signing_clients_exception(): void
    {
        $bedrock = new RecordingBedrock(new MockResponse('', ['error' => 'Connection refused']));

        try {
            new BedrockProvider($bedrock->client(), self::HAIKU)->chat(new UserMessage('ping'));
            self::fail('A refused connection was answered as a message.');
        } catch (ProviderException $e) {
            self::assertInstanceOf(NetworkFailureException::class, $e->getPrevious());
        }

        self::assertCount(1, $bedrock->requests, 'The request was sent more than once.');
    }

    public function test_a_buffered_answer_one_byte_past_the_ceiling_is_refused(): void
    {
        $body = json_encode(self::ANSWER, JSON_THROW_ON_ERROR);
        $answer = static fn (): MockResponse => new MockResponse($body, ['response_headers' => ['Content-Type' => 'application/json']]);
        $bedrock = new RecordingBedrock($answer(), $answer());

        self::assertSame(
            'pong',
            new BedrockProvider($bedrock->client(), self::HAIKU, maxResponseBytes: \strlen($body))->chat(new UserMessage('ping'))->message()->getContent(),
        );

        $this->expectExceptionObject(new ProviderException(\sprintf(EventStreamDecoder::TOO_LARGE, \strlen($body) - 1)));

        new BedrockProvider($bedrock->client(), self::HAIKU, maxResponseBytes: \strlen($body) - 1)->chat(new UserMessage('ping'));
    }

    public function test_a_stream_without_message_stop_ended_early(): void
    {
        $bedrock = new RecordingBedrock(RecordingBedrock::frames(
            EventStreamFrame::event('contentBlockDelta', ['contentBlockIndex' => 0, 'delta' => ['text' => 'Hel']]),
        ));

        $this->expectExceptionObject(new ProviderException('The stream ended before the answer was complete.'));

        iterator_to_array(new BedrockProvider($bedrock->client(), self::HAIKU)->stream(new UserMessage('ping')));
    }

    public function test_set_http_client_changes_nothing(): void
    {
        $bedrock = new RecordingBedrock(RecordingBedrock::json(self::ANSWER));
        $provider = new BedrockProvider($bedrock->client(), self::HAIKU);

        self::assertSame($provider, $provider->setHttpClient(new NeuronHttpClient(new Http(), 'https://elsewhere.example')));
        self::assertInstanceOf(ProviderResponse::class, $provider->chat(new UserMessage('ping')));
        self::assertStringStartsWith(RecordingBedrock::ORIGIN . '/', $bedrock->requests[0]['url']);
    }

    /**
     * Six cache markers, of which Converse accepts the last four.
     *
     * @return list<Message>
     */
    private static function conversation(): array
    {
        $cached = static fn (string $text): TextContent => new TextContent($text)->cache();

        return [
            new UserMessage([$cached('one'), $cached('two')]),
            new AssistantMessage([$cached('three')]),
            new UserMessage([$cached('four'), $cached('five')]),
            new AssistantMessage('six'),
            new UserMessage([$cached('seven')]),
        ];
    }

    /**
     * What neuron-ai's own BedrockRuntime builds for the same input. Its
     * constructor needs the AWS SDK, which is not installed, so it is built
     * without one: createPayLoad() never touches the SDK client.
     *
     * @param array<string, mixed> $inferenceConfig
     * @param list<\NeuronAI\Tools\ToolInterface> $tools
     * @param list<Message> $messages
     *
     * @return array<string, mixed>
     */
    private static function neuronPayload(string $model, array $inferenceConfig, string $system, array $tools, array $messages): array
    {
        $class = new ReflectionClass(BedrockRuntime::class);
        $runtime = $class->newInstanceWithoutConstructor();
        $class->getProperty('model')->setValue($runtime, $model);
        $class->getProperty('inferenceConfig')->setValue($runtime, $inferenceConfig);
        $runtime->systemPrompt($system)->setTools($tools);

        return $class->getMethod('createPayLoad')->invoke($runtime, $messages);
    }
}
