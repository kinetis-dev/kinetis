<?php

declare(strict_types=1);

namespace Kinetis\Neuron\Tests\Linting\Fixtures;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\EloquentMessageStore;
use NeuronAI\Chat\History\FileMessageStore;
use NeuronAI\Chat\History\SQLMessageStore;
use NeuronAI\Evaluation\Cache\FileEvaluationCache;
use NeuronAI\Evaluation\Usage\UsageMeter;
use NeuronAI\HttpClient\Amp\AmpHttpClient;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\MCP\McpConnector;
use NeuronAI\MCP\SseHttpTransport;
use NeuronAI\MCP\StdioTransport;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\OpenAI\OpenAI as OpenAiProvider;
use NeuronAI\RAG\DataLoader\FileDataLoader;
use NeuronAI\RAG\DataLoader\PdfReader;
use NeuronAI\RAG\Embeddings\OpenAIEmbeddingsProvider;
use NeuronAI\RAG\GraphStore\Neo4jGraphStore;
use NeuronAI\RAG\VectorStore\FileVectorStore;
use NeuronAI\RAG\VectorStore\MariaDBVectorStore;
use NeuronAI\RAG\VectorStore\MongoDBVectorStore;
use NeuronAI\RAG\VectorStore\QdrantVectorStore;
use NeuronAI\Tools\Toolkits\AWS\SESTool;
use NeuronAI\Tools\Toolkits\FileSystem\BashTool;
use NeuronAI\Tools\Toolkits\FileSystem\DeleteFileTool;
use NeuronAI\Tools\Toolkits\FileSystem\EditFileTool;
use NeuronAI\Tools\Toolkits\FileSystem\FileSystemToolkit;
use NeuronAI\Tools\Toolkits\FileSystem\GlobPathTool;
use NeuronAI\Tools\Toolkits\FileSystem\GrepFileContentTool;
use NeuronAI\Tools\Toolkits\FileSystem\ParseFileTool;
use NeuronAI\Tools\Toolkits\FileSystem\ReadFileTool;
use NeuronAI\Tools\Toolkits\FileSystem\WriteFileTool;
use NeuronAI\Tools\Toolkits\MySQL\MySQLSchemaTool;
use NeuronAI\Tools\Toolkits\MySQL\MySQLSelectTool;
use NeuronAI\Tools\Toolkits\MySQL\MySQLToolkit;
use NeuronAI\Tools\Toolkits\MySQL\MySQLWriteTool;
use NeuronAI\Tools\Toolkits\PGSQL\PGSQLSchemaTool;
use NeuronAI\Tools\Toolkits\PGSQL\PGSQLSelectTool;
use NeuronAI\Tools\Toolkits\PGSQL\PGSQLToolkit;
use NeuronAI\Tools\Toolkits\PGSQL\PGSQLWriteTool;
use NeuronAI\Workflow\Persistence\DatabasePersistence;
use NeuronAI\Workflow\Persistence\EloquentPersistence;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\Persistence\IgbinarySerializer;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use NeuronAI\Workflow\Persistence\RedisPersistence;
use PDO;

final class WorkerUnsafeNeuronCalls
{
    public function missingClients(): void
    {
        new Anthropic('key', 'model');
        new OpenAiProvider('key', 'model', httpClient: null);
        new McpConnector(['url' => 'https://tools.example.com/mcp']);
        McpConnector::make(['url' => 'https://tools.example.com/mcp']);
        new QdrantVectorStore('http://qdrant:6333/collections/docs');
        new OpenAIEmbeddingsProvider('key', 'model');
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

    public function blockingStorage(PDO $pdo, mixed $redis): void
    {
        new FilePersistence('/tmp');
        new DatabasePersistence($pdo);
        new FileMessageStore('/tmp');
        new SQLMessageStore('thread', $pdo);
        new PhpSerializer();
        new RedisPersistence($redis);
        new EloquentPersistence('App\\Workflow');
        new EloquentMessageStore('App\\Message');
        new IgbinarySerializer();
    }

    public function blockingToolkits(PDO $pdo, mixed $ses): void
    {
        new MySQLToolkit($pdo);
        MySQLToolkit::make($pdo);
        new MySQLSchemaTool($pdo);
        new MySQLSelectTool($pdo);
        new MySQLWriteTool($pdo);
        new PGSQLToolkit($pdo);
        new PGSQLSchemaTool($pdo);
        new PGSQLSelectTool($pdo);
        PGSQLWriteTool::make($pdo);
        new FileSystemToolkit();
        new BashTool();
        new DeleteFileTool();
        new EditFileTool();
        new GlobPathTool();
        new GrepFileContentTool();
        new ParseFileTool();
        new ReadFileTool();
        new WriteFileTool();
        new SESTool($ses, 'from@example.com');
    }

    public function blockingRag(PDO $pdo, mixed $mongo): void
    {
        new FileDataLoader('/docs');
        FileDataLoader::for('/docs');
        new PdfReader();
        new Neo4jGraphStore();
        new FileVectorStore('/tmp');
        new MariaDBVectorStore($pdo);
        new MongoDBVectorStore($mongo, 'rag');
        new FileEvaluationCache('/tmp');
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

    public function awsSdkBedrock(mixed $bedrockRuntimeClient): void
    {
        new \NeuronAI\Providers\AWS\BedrockRuntime($bedrockRuntimeClient, 'anthropic.claude-3-haiku-20240307-v1:0');
        new \NeuronAI\RAG\Embeddings\AwsBedrockEmbeddingsProvider($bedrockRuntimeClient);
    }
}
