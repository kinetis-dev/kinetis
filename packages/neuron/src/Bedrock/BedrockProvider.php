<?php

declare(strict_types=1);

namespace Kinetis\Neuron\Bedrock;

use Generator;
use JsonException;
use Kinetis\AwsSigV4\SigV4SigningClient;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolArgumentChunk;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\AWS\HandleStructured;
use NeuronAI\Providers\AWS\MessageMapper;
use NeuronAI\Providers\AWS\StreamState;
use NeuronAI\Providers\AWS\ToolMapper;
use NeuronAI\Providers\HandleWithTools;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Tools\ToolCall;

/**
 * Amazon Bedrock's Converse and ConverseStream APIs as a Neuron provider,
 * sent through a {@see SigV4SigningClient} instead of the AWS SDK.
 *
 * The client's origin is the Bedrock Runtime endpoint,
 * `https://bedrock-runtime.{region}.amazonaws.com`, and its service is
 * `bedrock`; every request goes to that origin, once. The messages and
 * tools are mapped by Neuron's own Bedrock mappers, and the answer is
 * shaped as Neuron's `BedrockRuntime` shapes it.
 *
 * Every response byte, buffered or streamed, counts against
 * $maxResponseBytes. The deadline is the client's transport's.
 *
 * The provider holds the system prompt, the tools and a stream's state, so
 * it is built for one request.
 */
final class BedrockProvider implements AIProviderInterface
{
    use HandleWithTools;
    use HandleStructured;

    private const int MAX_CACHE_POINTS = 4;

    private const string JSON = 'application/json';

    private const string EVENT_STREAM = 'application/vnd.amazon.eventstream';

    private ?string $system = null;

    private readonly BedrockApi $api;

    private StreamState $streamState;

    /**
     * @param array<string, mixed> $inferenceConfig Converse's `inferenceConfig`, sent as given.
     */
    public function __construct(
        SigV4SigningClient $client,
        private readonly string $model,
        private readonly array $inferenceConfig = [],
        int $maxResponseBytes = 8 * 1024 * 1024,
    ) {
        $this->api = new BedrockApi($client, $maxResponseBytes);
    }

    #[\Override]
    public function getModel(): string
    {
        return $this->model;
    }

    #[\Override]
    public function systemPrompt(SystemMessage|string|null $prompt): AIProviderInterface
    {
        $this->system = $prompt instanceof SystemMessage ? $prompt->getContent() : $prompt;

        return $this;
    }

    /** Bedrock is reached through the signing client only. */
    #[\Override]
    public function setHttpClient(HttpClientInterface $client): AIProviderInterface
    {
        return $this;
    }

    /** @throws ProviderException */
    #[\Override]
    public function chat(Message ...$messages): ProviderResponse
    {
        $response = $this->api->post($this->model, 'converse', $this->body($messages), self::JSON);
        $result = $this->api->json($response);

        // Bedrock reports cache reads and writes apart from inputTokens: the whole prompt adds them back
        $usage = new Usage(
            ($result['usage']['inputTokens'] ?? 0)
                + ($result['usage']['cacheReadInputTokens'] ?? 0)
                + ($result['usage']['cacheWriteInputTokens'] ?? 0),
            $result['usage']['outputTokens'] ?? 0,
            $result['usage']['cacheReadInputTokens'] ?? 0,
        );

        $message = $this->createResponseMessage($result['output']['message']['content'] ?? []);
        $message->setUsage($usage);
        $message->setStopReason($result['stopReason'] ?? '');

        return new ProviderResponse(message: $message);
    }

    /**
     * The ConverseStream events, read and decoded as they arrive. Leaving
     * the generator early closes the response.
     *
     * @throws ProviderException
     */
    #[\Override]
    public function stream(Message ...$messages): Generator
    {
        $response = $this->api->post($this->model, 'converse-stream', $this->body($messages), self::EVENT_STREAM);
        $body = $response->getBody();

        try {
            $this->streamState = new StreamState();

            $toolContents = [];
            $toolPositions = [];
            $redactedReasoning = [];
            $stopReason = null;
            $toolContent = null;

            foreach ($this->api->decoder($body)->events() as [$type, $payload]) {
                $event = [$type => $payload];

                if (isset($event['metadata'])) {
                    // Bedrock reports cache reads and writes apart from inputTokens: the whole prompt adds them back
                    $this->streamState->addInputTokens(
                        ($event['metadata']['usage']['inputTokens'] ?? 0)
                        + ($event['metadata']['usage']['cacheReadInputTokens'] ?? 0)
                        + ($event['metadata']['usage']['cacheWriteInputTokens'] ?? 0)
                    );
                    $this->streamState->addOutputTokens($event['metadata']['usage']['outputTokens'] ?? 0);
                    $this->streamState->addCachedInputTokens(
                        $event['metadata']['usage']['cacheReadInputTokens'] ?? 0
                    );
                }

                if (isset($event['messageStop']['stopReason'])) {
                    $stopReason = $event['messageStop']['stopReason'];
                }

                if (isset($event['contentBlockStart']['start']['toolUse'])) {
                    $toolPositions[] = $event['contentBlockStart']['contentBlockIndex'];
                    $toolContent = $event['contentBlockStart']['start'];
                    $toolContent['toolUse']['input'] = '';
                    continue;
                }

                if (isset($event['contentBlockDelta']['delta']['text'])) {
                    $textChunk = $event['contentBlockDelta']['delta']['text'];
                    $this->streamState->updateContentBlock(
                        $event['contentBlockDelta']['contentBlockIndex'],
                        new TextContent($textChunk)
                    );
                    yield new TextChunk($this->streamState->messageId(), $textChunk);
                }

                if (isset($event['contentBlockDelta']['delta']['reasoningContent'])) {
                    $reasoningContent = $event['contentBlockDelta']['delta']['reasoningContent'];
                    $contentBlockIndex = $event['contentBlockDelta']['contentBlockIndex'];

                    if (isset($reasoningContent['text'])) {
                        $this->streamState->updateContentBlock(
                            $contentBlockIndex,
                            new ReasoningContent($reasoningContent['text'])
                        );
                        if ($reasoningContent['text'] !== '') {
                            yield new ReasoningChunk($this->streamState->messageId(), $reasoningContent['text']);
                        }
                    }

                    if (isset($reasoningContent['redactedContent'])) {
                        // A blob travels base64-encoded: the bytes are joined, then encoded once for history
                        $redactedReasoning[$contentBlockIndex] ??= '';
                        $redactedReasoning[$contentBlockIndex] .= base64_decode($reasoningContent['redactedContent']);
                    }

                    if (isset($reasoningContent['signature'])) {
                        $this->streamState->signReasoningContentBlock($contentBlockIndex, $reasoningContent['signature']);
                    }

                    continue;
                }

                if ($toolContent !== null && isset($event['contentBlockStop'])) {
                    $toolContents[] = $toolContent;
                    $toolContent = null;
                }

                if ($toolContent !== null && isset($event['contentBlockDelta']['delta']['toolUse'])) {
                    $input = $event['contentBlockDelta']['delta']['toolUse']['input'];
                    $toolContent['toolUse']['input'] .= $input;

                    if ($input !== '') {
                        yield new ToolArgumentChunk(
                            $this->streamState->messageId(),
                            $toolContent['toolUse']['name'],
                            $input,
                            $toolContent['toolUse']['toolUseId'] ?? null,
                        );
                    }
                }
            }
        } finally {
            $body->close();
        }

        if ($toolContent !== null) {
            $toolContents[] = $toolContent;
        }

        // messageStop carries the stop reason: without it the stream ended early
        if ($stopReason === null) {
            throw new ProviderException('The stream ended before the answer was complete.');
        }

        // Tool calls are built only for a tool_use stop: a call cut off by max_tokens has incomplete arguments
        if ($stopReason === 'tool_use' && $toolContents !== []) {
            $tools = array_map($this->createTool(...), $toolContents);
            $message = new ToolCallMessage($this->streamState->getContentBlocks(), $tools);
        } else {
            $message = new AssistantMessage($this->streamState->getContentBlocks());
        }

        if ($redactedReasoning !== []) {
            $message->addMetadata('aws_redacted_reasoning', array_map(base64_encode(...), $redactedReasoning));
        }
        if ($toolPositions !== []) {
            $message->addMetadata('aws_tool_positions', $toolPositions);
        }
        $message->setId($this->streamState->messageId())->setUsage($this->streamState->getUsage());

        $message->setStopReason($stopReason);

        return new ProviderResponse(message: $message);
    }

    /**
     * The Converse request body: Neuron's Bedrock payload without the model
     * ID, which travels in the path.
     *
     * @param array<Message> $messages
     *
     * @throws ProviderException
     */
    private function body(array $messages): string
    {
        $payload = $this->createPayLoad($messages);
        unset($payload['modelId']);
        $payload['messages'] = array_map(self::encodeMessageBlobs(...), $payload['messages']);

        try {
            return json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ProviderException('The Bedrock request could not be encoded as JSON.', previous: $e);
        }
    }

    /**
     * Neuron's BedrockRuntime payload, as its createPayLoad() builds it.
     *
     * @param array<Message> $messages
     *
     * @return array<string, mixed>
     *
     * @throws ProviderException
     */
    private function createPayLoad(array $messages): array
    {
        $payload = [
            'modelId' => $this->model,
            'messages' => $this->limitCachePoints(new MessageMapper()->map($messages)),
        ];

        // An absent or empty text block is refused
        if ($this->system !== null && $this->system !== '') {
            $payload['system'] = [['text' => $this->system]];
        }

        if (\count($this->inferenceConfig) > 0) {
            $payload['inferenceConfig'] = $this->inferenceConfig;
        }

        $tools = new ToolMapper()->map($this->tools);

        if ($tools !== []) {
            $payload['toolConfig']['tools'] = $tools;
        }

        return $payload;
    }

    /**
     * Converse refuses a request with more cache points than it allows, and the
     * markers stored with a conversation add up over a long thread: the most
     * recent ones stay.
     *
     * @param array<int, array<string, mixed>> $messages
     *
     * @return array<int, array<string, mixed>>
     */
    private function limitCachePoints(array $messages): array
    {
        $slots = self::MAX_CACHE_POINTS;

        foreach (array_reverse(array_keys($messages)) as $index) {
            $content = $messages[$index]['content'];

            foreach (array_reverse(array_keys($content)) as $position) {
                if (!isset($content[$position]['cachePoint'])) {
                    continue;
                }

                if ($slots > 0) {
                    $slots--;
                } else {
                    unset($content[$position]);
                }
            }

            $messages[$index]['content'] = array_values($content);
        }

        return $messages;
    }

    /**
     * Neuron's mapper hands the AWS SDK raw bytes for its blob members, and
     * the SDK base64-encodes them into the JSON body. Here the encoding is
     * this provider's: media `source.bytes`, in a message and in a tool
     * result, and `reasoningContent.redactedContent`.
     *
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>
     */
    private static function encodeMessageBlobs(array $message): array
    {
        $message['content'] = array_map(self::encodeBlockBlobs(...), $message['content']);

        return $message;
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return array<string, mixed>
     */
    private static function encodeBlockBlobs(array $block): array
    {
        foreach (['image', 'document', 'video', 'audio'] as $media) {
            if (isset($block[$media]['source']['bytes'])) {
                $block[$media]['source']['bytes'] = base64_encode($block[$media]['source']['bytes']);
            }
        }

        if (isset($block['reasoningContent']['redactedContent'])) {
            $block['reasoningContent']['redactedContent'] = base64_encode($block['reasoningContent']['redactedContent']);
        }

        if (isset($block['toolResult']['content'])) {
            $block['toolResult']['content'] = array_map(self::encodeBlockBlobs(...), $block['toolResult']['content']);
        }

        return $block;
    }

    /**
     * Neuron's BedrockRuntime response shaping. A redacted reasoning block
     * arrives base64-encoded, the form history stores it in.
     *
     * @param array<int, array<string, mixed>> $contents
     *
     * @throws ProviderException
     */
    private function createResponseMessage(array $contents): AssistantMessage
    {
        $blocks = [];
        $tools = [];
        $toolPositions = [];
        $redactedReasoning = [];

        foreach ($contents as $index => $content) {
            if (isset($content['text'])) {
                $blocks[] = new TextContent($content['text']);
                continue;
            }

            if (isset($content['reasoningContent']['reasoningText'])) {
                $reasoningText = $content['reasoningContent']['reasoningText'];
                $blocks[] = new ReasoningContent(
                    $reasoningText['text'],
                    $reasoningText['signature'] ?? null,
                );
            }

            if (isset($content['reasoningContent']['redactedContent'])) {
                $redactedReasoning[$index] = $content['reasoningContent']['redactedContent'];
            }

            if (isset($content['toolUse'])) {
                $tools[] = $this->createTool($content);
                $toolPositions[] = $index;
            }
        }

        $message = $tools === [] ? new AssistantMessage($blocks) : new ToolCallMessage($blocks, $tools);
        if ($redactedReasoning !== []) {
            $message->addMetadata('aws_redacted_reasoning', $redactedReasoning);
        }
        if ($toolPositions !== []) {
            $message->addMetadata('aws_tool_positions', $toolPositions);
        }

        return $message;
    }

    /**
     * @param array<string, mixed> $toolContent
     *
     * @throws ProviderException
     */
    private function createTool(array $toolContent): ToolCall
    {
        $toolUse = $toolContent['toolUse'];

        return $this->newToolCall(
            $toolUse['name'],
            $toolUse['toolUseId'],
            $this->decodeToolArguments($toolUse['name'], $toolUse['input'] ?? null),
        );
    }
}
