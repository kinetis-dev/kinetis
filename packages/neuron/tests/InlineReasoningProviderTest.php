<?php

declare(strict_types=1);

namespace Kinetis\Neuron\Tests;

use Generator;
use Kinetis\Neuron\InlineReasoningProvider;
use Kinetis\Neuron\NeuronHttpClient;
use Kinetis\Neuron\Tests\Fixtures\ScriptedStreamProvider;
use Kinetis\Neuron\Tests\Fixtures\Verdict;
use Kinetis\RevoltHttpClient\Http;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\FrontendTool;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;

final class InlineReasoningProviderTest extends TestCase
{
    public function test_a_tag_split_across_text_chunks_comes_out_as_reasoning(): void
    {
        $inner = new ScriptedStreamProvider(
            self::response(new AssistantMessage('Hi <thinking>x</thinking> there')),
            new TextChunk('msg-1', 'Hi <thi'),
            new TextChunk('msg-1', 'nking>x</thinking> there'),
        );

        [$chunks] = self::read(new InlineReasoningProvider($inner)->stream(new UserMessage('ping')));

        self::assertSame([
            [TextChunk::class, 'msg-1', 'Hi '],
            [ReasoningChunk::class, 'msg-1', 'x'],
            [TextChunk::class, 'msg-1', ' there'],
        ], $chunks);
    }

    public function test_text_held_back_is_flushed_before_a_tool_call_and_order_is_kept(): void
    {
        $call = new ToolCallChunk('msg-1', new ToolCall('find_orders', 'call-1'));
        $inner = new ScriptedStreamProvider(
            self::response(new AssistantMessage('')),
            new TextChunk('msg-1', '<thinking>Query them.'),
            new TextChunk('msg-1', '</thinking>Checking <'),
            $call,
            new TextChunk('msg-2', '<think>Low.'),
            $call,
            new TextChunk('msg-3', 'Done.'),
        );

        [$chunks] = self::read(new InlineReasoningProvider($inner)->stream());

        self::assertSame([
            [ReasoningChunk::class, 'msg-1', 'Query them.'],
            [TextChunk::class, 'msg-1', 'Checking '],
            [TextChunk::class, 'msg-1', '<'],
            [ToolCallChunk::class, 'msg-1', null],
            [ReasoningChunk::class, 'msg-2', 'Low.'],
            [ToolCallChunk::class, 'msg-1', null],
            [TextChunk::class, 'msg-3', 'Done.'],
        ], $chunks, 'a block left open before a tool call ends there');
    }

    public function test_the_returned_message_keeps_no_inline_reasoning(): void
    {
        $message = new AssistantMessage("<thinking>Plan.</thinking>\n\nHi <think>x</think>there");
        $inner = new ScriptedStreamProvider(
            self::response($message),
            new TextChunk('msg-1', "<thinking>Plan.</thinking>\n\nHi <think>x</think>there"),
        );

        [, $response] = self::read(new InlineReasoningProvider($inner)->stream());

        self::assertSame($message, $response->message());
        self::assertSame('Hi there', $response->message()->getContent());
    }

    public function test_untagged_text_passes_byte_for_byte(): void
    {
        $text = ['Hello', ', 2 < 3', " and <b>4</b>\n", '  is <thin ice>', ' x <', 'th', ' 😀'];
        $message = new AssistantMessage(implode('', $text));
        $message->setUsage(new Usage(7, 3));
        $call = new ToolCallChunk('msg-1', new ToolCall('find_orders', 'call-1'));
        $response = self::response($message);
        $inner = new ScriptedStreamProvider(
            $response,
            ...[...array_map(static fn (string $delta): TextChunk => new TextChunk('msg-1', $delta), $text), $call],
        );

        [$chunks, $returned] = self::read(new InlineReasoningProvider($inner)->stream());

        self::assertSame([
            [TextChunk::class, 'msg-1', 'Hello'],
            [TextChunk::class, 'msg-1', ', 2 < 3'],
            [TextChunk::class, 'msg-1', " and <b>4</b>\n"],
            [TextChunk::class, 'msg-1', '  is <thin ice>'],
            [TextChunk::class, 'msg-1', ' x '],
            [TextChunk::class, 'msg-1', '<th 😀'],
            [ToolCallChunk::class, 'msg-1', null],
        ], $chunks, 'only what could still start a tag waits for the next chunk');
        self::assertSame($response, $returned);
        self::assertSame(implode('', $text), $returned->message()->getContent());
        self::assertSame(7, $returned->message()->getUsage()?->inputTokens);
        self::assertSame(3, $returned->message()->getUsage()?->outputTokens);
    }

    public function test_other_chunks_pass_through_as_the_same_objects(): void
    {
        $reasoning = new ReasoningChunk('msg-1', 'structured reasoning');
        $call = new ToolCallChunk('msg-1', new ToolCall('find_orders', 'call-1'));
        $inner = new ScriptedStreamProvider(self::response(new AssistantMessage('')), $reasoning, $call);

        $out = iterator_to_array(new InlineReasoningProvider($inner)->stream(), false);

        self::assertSame([$reasoning, $call], $out);
    }

    public function test_chat_strips_the_text_and_keeps_the_usage_and_the_metadata(): void
    {
        $message = new AssistantMessage("<think>Plan.</think>\n\nAnswer.");
        $message->setUsage(new Usage(11, 5));
        $message->addMetadata('stop', 'end_turn');
        $inner = new FakeAIProvider($message);

        $response = new InlineReasoningProvider($inner)->chat(new UserMessage('ping'));

        self::assertSame($message, $response->message());
        self::assertSame('Answer.', $message->getContent());
        self::assertSame(11, $message->getUsage()?->inputTokens);
        self::assertSame(5, $message->getUsage()?->outputTokens);
        self::assertSame('end_turn', $message->getMetadata('stop'));
    }

    public function test_a_text_block_holding_only_reasoning_is_removed_and_other_blocks_stay(): void
    {
        $structured = new ReasoningContent('<thinking>structured</thinking>');
        $message = new ToolCallMessage([new TextContent('<thinking>Query them.</thinking>'), $structured], [new ToolCall('find_orders', 'call-1')]);
        $inner = new FakeAIProvider($message);

        $returned = new InlineReasoningProvider($inner)->chat()->message();

        self::assertSame([$structured], $returned->getContentBlocks());
        self::assertSame('<thinking>structured</thinking>', $structured->content, 'structured reasoning is no answer text');
        self::assertInstanceOf(ToolCallMessage::class, $returned);
        self::assertCount(1, $returned->getToolCalls());
    }

    public function test_untagged_text_blocks_stay_byte_for_byte(): void
    {
        $texts = ['', "\n ", 'a <', '<th', 'b'];
        $inner = new FakeAIProvider(new AssistantMessage(array_map(static fn (string $text): TextContent => new TextContent($text), $texts)));

        $blocks = new InlineReasoningProvider($inner)->chat()->message()->getContentBlocks();

        self::assertSame($texts, array_map(static fn (TextContent $block): string => $block->content, $blocks));
    }

    public function test_structured_output_is_parsed_from_the_text_without_its_reasoning(): void
    {
        $inner = new FakeAIProvider(new AssistantMessage('<thinking>It is {"level": "high"}?</thinking>{"level": "low"}'));

        $verdict = Agent::make()
            ->setAiProvider(new InlineReasoningProvider($inner))
            ->setThreadId('thread-1')
            ->structured(new UserMessage('ping'), Verdict::class, 0);

        self::assertInstanceOf(Verdict::class, $verdict);
        self::assertSame('low', $verdict->level);
        $inner->assertMethodCallCount('structured', 1);
    }

    public function test_an_agent_streams_the_reasoning_and_keeps_none_of_it_in_its_history(): void
    {
        $inner = new FakeAIProvider(new AssistantMessage('<thinking>Plan it.</thinking>The answer.'));
        $inner->setStreamChunkSize(3);
        $agent = Agent::make()->setAiProvider(new InlineReasoningProvider($inner))->setThreadId('thread-1');

        $text = '';
        $reasoning = '';

        foreach ($agent->stream(new UserMessage('ping')) as $chunk) {
            if ($chunk instanceof TextChunk) {
                $text .= $chunk->content;
            } elseif ($chunk instanceof ReasoningChunk) {
                $reasoning .= $chunk->content;
            }
        }

        self::assertSame('The answer.', $text);
        self::assertSame('Plan it.', $reasoning);
        self::assertSame('The answer.', $agent->getChatHistory()->getLastMessage()->getContent());
    }

    public function test_configuration_reaches_the_wrapped_provider_and_returns_the_decorator(): void
    {
        $inner = new FakeAIProvider(new AssistantMessage('pong'));
        $provider = new InlineReasoningProvider($inner);
        $tool = new FrontendTool('find_orders', 'Finds orders.');
        $client = new NeuronHttpClient(new Http(), 'https://api.example.com');

        self::assertSame($provider, $provider->systemPrompt('Be brief.'));
        self::assertSame($provider, $provider->setTools([$tool]));
        self::assertSame($provider, $provider->setHttpClient($client));
        self::assertSame('fake', $provider->getModel());
        self::assertSame($inner, $provider->provider());

        $provider->chat(new UserMessage('ping'));

        $inner->assertSystemPrompt('Be brief.');
        $inner->assertToolsConfigured(['find_orders']);
    }

    public function test_set_http_client_reaches_the_wrapped_provider(): void
    {
        $client = new NeuronHttpClient(new Http(), 'https://api.example.com');
        $inner = $this->createMock(AIProviderInterface::class);
        $inner->expects(self::once())->method('setHttpClient')->with($client)->willReturnSelf();

        $provider = new InlineReasoningProvider($inner);

        self::assertSame($provider, $provider->setHttpClient($client));
    }

    private static function response(AssistantMessage|ToolCallMessage $message): ProviderResponse
    {
        return new ProviderResponse($message);
    }

    /**
     * The chunks $stream yields, as class, message id and text, and what it returns.
     *
     * @param Generator<int, StreamChunk, mixed, ProviderResponse> $stream
     * @return array{list<array{class-string, ?string, ?string}>, ProviderResponse}
     */
    private static function read(Generator $stream): array
    {
        $chunks = [];

        foreach ($stream as $chunk) {
            $chunks[] = [
                $chunk::class,
                $chunk->messageId,
                $chunk instanceof TextChunk || $chunk instanceof ReasoningChunk ? $chunk->content : null,
            ];
        }

        return [$chunks, $stream->getReturn()];
    }
}
