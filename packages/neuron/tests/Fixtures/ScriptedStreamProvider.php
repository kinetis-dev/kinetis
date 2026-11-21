<?php

declare(strict_types=1);

namespace Kinetis\Neuron\Tests\Fixtures;

use Generator;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Testing\FakeAIProvider;

/** A fake provider whose stream yields exactly the given chunks, then returns the given response. */
final class ScriptedStreamProvider extends FakeAIProvider
{
    /** @var list<StreamChunk> */
    private readonly array $chunks;

    public function __construct(private readonly ProviderResponse $response, StreamChunk ...$chunks)
    {
        parent::__construct();
        $this->chunks = array_values($chunks);
    }

    /** @return Generator<int, StreamChunk, mixed, ProviderResponse> */
    #[\Override]
    public function stream(Message ...$messages): Generator
    {
        yield from $this->chunks;

        return $this->response;
    }
}
