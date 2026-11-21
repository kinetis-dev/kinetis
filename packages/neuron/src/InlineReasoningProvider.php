<?php

declare(strict_types=1);

namespace Kinetis\Neuron;

use Generator;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\ProviderResponse;

/**
 * Wraps a provider whose model writes its reasoning inline, in the
 * answer's text, so the reasoning reaches the stream as reasoning and
 * never reaches the answer or the chat history. {@see InlineReasoning}
 * names the tags it reads.
 *
 * A stream's text chunks come out as their visible text and, under the
 * same message id, the reasoning taken out of them, the reasoning first.
 * Every other chunk passes through in order, after the text held back
 * before it. The answer chat(), stream() and structured() return keeps
 * its message, usage and metadata, with the reasoning taken out of its
 * text: a text block left empty is removed.
 *
 * Holds the inner provider, so it is built per request with it.
 *
 * {@link https://kinetis.dev/docs/neuron.html} states the contract.
 */
final readonly class InlineReasoningProvider implements AIProviderInterface
{
    public function __construct(private AIProviderInterface $provider)
    {
    }

    /** The wrapped provider. */
    public function provider(): AIProviderInterface
    {
        return $this->provider;
    }

    #[\Override]
    public function getModel(): string
    {
        return $this->provider->getModel();
    }

    #[\Override]
    public function systemPrompt(SystemMessage|string|null $prompt): self
    {
        $this->provider->systemPrompt($prompt);

        return $this;
    }

    #[\Override]
    public function setTools(array $tools): self
    {
        $this->provider->setTools($tools);

        return $this;
    }

    #[\Override]
    public function setHttpClient(HttpClientInterface $client): self
    {
        $this->provider->setHttpClient($client);

        return $this;
    }

    #[\Override]
    public function chat(Message ...$messages): ProviderResponse
    {
        return self::stripped($this->provider->chat(...$messages));
    }

    #[\Override]
    public function structured(array|Message $messages, string $class, array $response_schema): ProviderResponse
    {
        return self::stripped($this->provider->structured($messages, $class, $response_schema));
    }

    /** @return Generator<int, StreamChunk, mixed, ProviderResponse> */
    #[\Override]
    public function stream(Message ...$messages): Generator
    {
        $stream = $this->provider->stream(...$messages);
        $reasoning = new InlineReasoning();
        // the message id of the text part being read, null between text parts
        $part = null;

        foreach ($stream as $chunk) {
            if ($chunk instanceof TextChunk) {
                $part = (string) $chunk->messageId;
                yield from self::chunks($part, $reasoning->feed($chunk->content), $reasoning);

                continue;
            }

            if ($part !== null) {
                yield from self::chunks($part, $reasoning->finish(), $reasoning);
                $part = null;
            }

            yield $chunk;
        }

        if ($part !== null) {
            yield from self::chunks($part, $reasoning->finish(), $reasoning);
        }

        return self::stripped($stream->getReturn());
    }

    /**
     * The reasoning $reasoning took out, then $visible, as chunks of the
     * message $messageId.
     *
     * @return Generator<int, StreamChunk, mixed, void>
     */
    private static function chunks(string $messageId, string $visible, InlineReasoning $reasoning): Generator
    {
        $taken = $reasoning->reasoning();

        if ($taken !== '') {
            yield new ReasoningChunk($messageId, $taken);
        }

        if ($visible !== '') {
            yield new TextChunk($messageId, $visible);
        }
    }

    /** $response, its message's text blocks read as one answer, each block a text part. */
    private static function stripped(ProviderResponse $response): ProviderResponse
    {
        $message = $response->message();
        $reasoning = new InlineReasoning();
        $blocks = [];

        foreach ($message->getContentBlocks() as $block) {
            if ($block instanceof TextContent && !$block instanceof ReasoningContent) {
                $visible = $reasoning->feed($block->content) . $reasoning->finish();

                if ($visible === '' && $block->content !== '') {
                    continue;
                }

                $block->content = $visible;
            }

            $blocks[] = $block;
        }

        $message->setContents($blocks);

        return $response;
    }
}
