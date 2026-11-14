<?php

declare(strict_types=1);

namespace Kinetis\Neuron\Tests\Linting\Fixtures;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\FileMessageStore;
use NeuronAI\Chat\History\SQLMessageStore;
use NeuronAI\Evaluation\Usage\UsageMeter;
use NeuronAI\HttpClient\Amp\AmpHttpClient;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\MCP\McpConnector;
use NeuronAI\MCP\SseHttpTransport;
use NeuronAI\MCP\StdioTransport;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\OpenAI\OpenAI as OpenAiProvider;
use NeuronAI\Workflow\Persistence\DatabasePersistence;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use PDO;

final class WorkerUnsafeNeuronCalls
{
    public function missingClients(): void
    {
        new Anthropic('key', 'model');
        new OpenAiProvider('key', 'model', httpClient: null);
        new McpConnector(['url' => 'https://tools.example.com/mcp']);
        McpConnector::make(['url' => 'https://tools.example.com/mcp']);
    }

    public function blockingClients(): void
    {
        new CurlHttpClient();
        new AmpHttpClient();
    }

    public function forks(Agent $agent, ?Agent $maybe, bool $flag): void
    {
        $agent->parallelToolCalls();
        $agent->parallelToolCalls(true);
        $maybe?->parallelToolCalls(enabled: $flag);
    }

    public function blockingStorage(PDO $pdo): void
    {
        new FilePersistence('/tmp');
        new DatabasePersistence($pdo);
        new FileMessageStore('/tmp');
        new SQLMessageStore('thread', $pdo);
        new PhpSerializer();
    }

    public function blockingMcpTransports(mixed $client): void
    {
        new StdioTransport(['command' => 'server']);
        new SseHttpTransport(['url' => 'https://tools.example.com/sse']);
        new McpConnector(['command' => 'server'], $client);
        McpConnector::make(['url' => 'https://tools.example.com/sse', 'async' => true], $client);
    }

    public function sharedState(Agent $agent): void
    {
        UsageMeter::watch($agent);
        new UsageMeter();
    }
}
