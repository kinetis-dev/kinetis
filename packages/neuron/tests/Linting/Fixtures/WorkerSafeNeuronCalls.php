<?php

declare(strict_types=1);

namespace Kinetis\Neuron\Tests\Linting\Fixtures;

use Kinetis\AwsSigV4\SigV4SigningClient;
use Kinetis\Neuron\Bedrock\BedrockEmbeddingsProvider;
use Kinetis\Neuron\Bedrock\BedrockProvider;
use Kinetis\Neuron\NeuronHttpClient;
use Kinetis\RevoltHttpClient\Http;
use NeuronAI\Agent\Agent;
use NeuronAI\MCP\McpConnector;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\RAG\DataLoader\StringDataLoader;
use NeuronAI\RAG\Embeddings\OpenAIEmbeddingsProvider;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use NeuronAI\RAG\VectorStore\QdrantVectorStore;

final class WorkerSafeNeuronCalls
{
    /**
     * @param array<string, mixed> $config
     * @param list<mixed> $arguments
     */
    public function calls(Agent $agent, NeuronHttpClient $client, array $config, array $arguments, string $class): void
    {
        new NeuronHttpClient(new Http(), 'https://api.anthropic.com/v1');
        new Anthropic('key', 'model', httpClient: $client);
        new Anthropic('key', 'model', '2023-06-01', 8192, [], $client);
        McpConnector::make(['url' => 'https://tools.example.com/mcp'], $client);
        new OpenAI(...$arguments);
        new $class('key', 'model');

        $agent->parallelToolCalls(false);
        $agent->parallelToolCalls(enabled: false);

        new McpConnector($config, $client);
        new McpConnector(['url' => 'https://tools.example.com/mcp', 'async' => false], $client);

        new QdrantVectorStore('http://qdrant:6333/collections/docs', httpClient: $client);
        new OpenAIEmbeddingsProvider('key', 'model', 1024, $client);
        new MemoryVectorStore();
        StringDataLoader::for('text');
    }

    public function bedrock(SigV4SigningClient $bedrock): void
    {
        new BedrockProvider($bedrock, 'anthropic.claude-3-haiku-20240307-v1:0');
        new BedrockEmbeddingsProvider($bedrock);
    }
}
