<?php

declare(strict_types=1);

namespace Kinetis\Neuron\Tests\Linting;

use Kinetis\Neuron\Linting\NoWorkerUnsafeNeuronRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Each analysis runs in its own process: in the suite's shared process, a
 * cold-cache analysis of these fixtures exceeds PHP's default memory limit.
 *
 * @extends RuleTestCase<NoWorkerUnsafeNeuronRule>
 */
#[RunTestsInSeparateProcesses]
final class NoWorkerUnsafeNeuronRuleTest extends RuleTestCase
{
    private const string CLIENT = '%s leaves its $%s argument empty, so Neuron builds a CurlHttpClient whose '
        . 'curl_exec() blocks the worker and its whole event loop for every call. '
        . 'Pass a Kinetis\Neuron\NeuronHttpClient.';

    private const string CURL = '%s waits on curl_exec() and blocks the worker and its whole event loop for every '
        . 'call. Use Kinetis\Neuron\NeuronHttpClient.';

    private const string AMP = '%s changes the instance withBaseUri() and withHeaders() are called on and reads a '
        . 'body with no size limit, so a shared instance keeps one request\'s settings for the next. '
        . 'Use Kinetis\Neuron\NeuronHttpClient.';

    private const string FORK = '%s makes the agent fork the worker process to run its tool calls. '
        . 'Leave parallel tool calls off in a persistent worker.';

    private const string STORAGE = '%s does blocking file, database or Redis I/O that stops the worker and its '
        . 'whole event loop. Keep the chat history and workflow state in Neuron\'s in-memory defaults, '
        . 'InMemoryMessageStore and InMemoryPersistence, which end with the request.';

    private const string SERIALIZER = '%s unserializes workflow records with no class restriction, so whoever can '
        . 'write the workflow store can run code in the worker. Keep workflow state in Neuron\'s default '
        . 'InMemoryPersistence, which ends with the request.';

    private const string BLOCKING = '%s does blocking file, database, process or network I/O that stops the worker '
        . 'and its whole event loop, and Neuron has no non-blocking form of it. Leave it out of a persistent worker.';

    private const string BEDROCK = '%s sends through the AWS SDK, whose calls block the worker and its whole event '
        . 'loop. Use Kinetis\Neuron\Bedrock\BedrockProvider over a Kinetis\AwsSigV4\SigV4SigningClient.';

    private const string BEDROCK_EMBEDDINGS = '%s sends through the AWS SDK, whose calls block the worker and its '
        . 'whole event loop. Use Kinetis\Neuron\Bedrock\BedrockEmbeddingsProvider over a '
        . 'Kinetis\AwsSigV4\SigV4SigningClient.';

    private const string MCP = '%s blocks the worker and its whole event loop: the stdio transport waits on a '
        . 'child process and the SSE transport opens its stream with fopen(). Give McpConnector a url, which '
        . 'uses Streamable HTTP, and a Kinetis\Neuron\NeuronHttpClient.';

    private const string USAGE = '%s records into a static that every request in the worker shares, so it keeps '
        . 'state between requests. Read token usage from each answer\'s Usage, or trace it with '
        . 'Kinetis\Neuron\InferenceTelemetry.';

    protected function getRule(): Rule
    {
        return new NoWorkerUnsafeNeuronRule(self::createReflectionProvider());
    }

    public function test_flags_every_category(): void
    {
        $this->analyse([__DIR__ . '/Fixtures/WorkerUnsafeNeuronCalls.php'], [
            [sprintf(self::CLIENT, 'new NeuronAI\Providers\Anthropic\Anthropic', 'httpClient'), 58],
            [sprintf(self::CLIENT, 'new NeuronAI\Providers\OpenAI\OpenAI', 'httpClient'), 59],
            [sprintf(self::CLIENT, 'new NeuronAI\MCP\McpConnector', 'httpClient'), 60],
            [sprintf(self::CLIENT, 'NeuronAI\MCP\McpConnector::make()', 'httpClient'), 61],
            [sprintf(self::CLIENT, 'new NeuronAI\RAG\VectorStore\QdrantVectorStore', 'httpClient'), 62],
            [sprintf(self::CLIENT, 'new NeuronAI\RAG\Embeddings\OpenAIEmbeddingsProvider', 'httpClient'), 63],
            [sprintf(self::CURL, 'new NeuronAI\HttpClient\Curl\CurlHttpClient'), 68],
            [sprintf(self::AMP, 'new NeuronAI\HttpClient\Amp\AmpHttpClient'), 69],
            [sprintf(self::FORK, 'NeuronAI\Agent\Agent::parallelToolCalls()'), 74],
            [sprintf(self::FORK, 'NeuronAI\Agent\Agent::parallelToolCalls()'), 75],
            [sprintf(self::FORK, 'NeuronAI\Agent\Agent::parallelToolCalls()'), 76],
            [sprintf(self::STORAGE, 'new NeuronAI\Workflow\Persistence\FilePersistence'), 81],
            [sprintf(self::STORAGE, 'new NeuronAI\Workflow\Persistence\DatabasePersistence'), 82],
            [sprintf(self::STORAGE, 'new NeuronAI\Chat\History\FileMessageStore'), 83],
            [sprintf(self::STORAGE, 'new NeuronAI\Chat\History\SQLMessageStore'), 84],
            [sprintf(self::SERIALIZER, 'new NeuronAI\Workflow\Persistence\PhpSerializer'), 85],
            [sprintf(self::STORAGE, 'new NeuronAI\Workflow\Persistence\RedisPersistence'), 86],
            [sprintf(self::STORAGE, 'new NeuronAI\Workflow\Persistence\EloquentPersistence'), 87],
            [sprintf(self::STORAGE, 'new NeuronAI\Chat\History\EloquentMessageStore'), 88],
            [sprintf(self::SERIALIZER, 'new NeuronAI\Workflow\Persistence\IgbinarySerializer'), 89],
            [sprintf(self::BLOCKING, 'new NeuronAI\Tools\Toolkits\MySQL\MySQLToolkit'), 94],
            [sprintf(self::BLOCKING, 'NeuronAI\Tools\Toolkits\MySQL\MySQLToolkit::make()'), 95],
            [sprintf(self::BLOCKING, 'new NeuronAI\Tools\Toolkits\MySQL\MySQLSchemaTool'), 96],
            [sprintf(self::BLOCKING, 'new NeuronAI\Tools\Toolkits\MySQL\MySQLSelectTool'), 97],
            [sprintf(self::BLOCKING, 'new NeuronAI\Tools\Toolkits\MySQL\MySQLWriteTool'), 98],
            [sprintf(self::BLOCKING, 'new NeuronAI\Tools\Toolkits\PGSQL\PGSQLToolkit'), 99],
            [sprintf(self::BLOCKING, 'new NeuronAI\Tools\Toolkits\PGSQL\PGSQLSchemaTool'), 100],
            [sprintf(self::BLOCKING, 'new NeuronAI\Tools\Toolkits\PGSQL\PGSQLSelectTool'), 101],
            [sprintf(self::BLOCKING, 'NeuronAI\Tools\Toolkits\PGSQL\PGSQLWriteTool::make()'), 102],
            [sprintf(self::BLOCKING, 'new NeuronAI\Tools\Toolkits\FileSystem\FileSystemToolkit'), 103],
            [sprintf(self::BLOCKING, 'new NeuronAI\Tools\Toolkits\FileSystem\BashTool'), 104],
            [sprintf(self::BLOCKING, 'new NeuronAI\Tools\Toolkits\FileSystem\DeleteFileTool'), 105],
            [sprintf(self::BLOCKING, 'new NeuronAI\Tools\Toolkits\FileSystem\EditFileTool'), 106],
            [sprintf(self::BLOCKING, 'new NeuronAI\Tools\Toolkits\FileSystem\GlobPathTool'), 107],
            [sprintf(self::BLOCKING, 'new NeuronAI\Tools\Toolkits\FileSystem\GrepFileContentTool'), 108],
            [sprintf(self::BLOCKING, 'new NeuronAI\Tools\Toolkits\FileSystem\ParseFileTool'), 109],
            [sprintf(self::BLOCKING, 'new NeuronAI\Tools\Toolkits\FileSystem\ReadFileTool'), 110],
            [sprintf(self::BLOCKING, 'new NeuronAI\Tools\Toolkits\FileSystem\WriteFileTool'), 111],
            [sprintf(self::BLOCKING, 'new NeuronAI\Tools\Toolkits\AWS\SESTool'), 112],
            [sprintf(self::BLOCKING, 'new NeuronAI\RAG\DataLoader\FileDataLoader'), 117],
            [sprintf(self::BLOCKING, 'NeuronAI\RAG\DataLoader\FileDataLoader::for()'), 118],
            [sprintf(self::BLOCKING, 'new NeuronAI\RAG\DataLoader\PdfReader'), 119],
            [sprintf(self::BLOCKING, 'new NeuronAI\RAG\GraphStore\Neo4jGraphStore'), 120],
            [sprintf(self::BLOCKING, 'new NeuronAI\RAG\VectorStore\FileVectorStore'), 121],
            [sprintf(self::BLOCKING, 'new NeuronAI\RAG\VectorStore\MariaDBVectorStore'), 122],
            [sprintf(self::BLOCKING, 'new NeuronAI\RAG\VectorStore\MongoDBVectorStore'), 123],
            [sprintf(self::BLOCKING, 'new NeuronAI\Evaluation\Cache\FileEvaluationCache'), 124],
            [sprintf(self::MCP, 'new NeuronAI\MCP\StdioTransport'), 129],
            [sprintf(self::MCP, 'new NeuronAI\MCP\SseHttpTransport'), 130],
            [sprintf(self::MCP, 'new NeuronAI\MCP\McpConnector'), 131],
            [sprintf(self::MCP, 'NeuronAI\MCP\McpConnector::make()'), 132],
            [sprintf(self::USAGE, 'NeuronAI\Evaluation\Usage\UsageMeter::watch()'), 137],
            [sprintf(self::USAGE, 'new NeuronAI\Evaluation\Usage\UsageMeter'), 138],
            [sprintf(self::BEDROCK, 'new NeuronAI\Providers\AWS\BedrockRuntime'), 143],
            [sprintf(self::BEDROCK_EMBEDDINGS, 'new NeuronAI\RAG\Embeddings\AwsBedrockEmbeddingsProvider'), 144],
        ]);
    }

    public function test_passes_an_explicit_client_disabled_parallel_calls_and_what_it_cannot_judge(): void
    {
        $this->analyse([__DIR__ . '/Fixtures/WorkerSafeNeuronCalls.php'], []);
    }

    public function test_passes_a_built_in_constructor_with_several_signatures(): void
    {
        $this->analyse([__DIR__ . '/Fixtures/BuiltInConstructorCalls.php'], []);
    }
}
