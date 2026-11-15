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

    private const string STORAGE = '%s does blocking file or PDO I/O that stops the worker and its whole event '
        . 'loop. Keep the chat history and workflow state in Neuron\'s in-memory defaults, InMemoryMessageStore '
        . 'and InMemoryPersistence, which end with the request.';

    private const string SERIALIZER = '%s unserializes workflow records with no class restriction, so whoever can '
        . 'write the workflow store can run code in the worker. Keep workflow state in Neuron\'s default '
        . 'InMemoryPersistence, which ends with the request.';

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
            [sprintf(self::CLIENT, 'new NeuronAI\Providers\Anthropic\Anthropic', 'httpClient'), 27],
            [sprintf(self::CLIENT, 'new NeuronAI\Providers\OpenAI\OpenAI', 'httpClient'), 28],
            [sprintf(self::CLIENT, 'new NeuronAI\MCP\McpConnector', 'httpClient'), 29],
            [sprintf(self::CLIENT, 'NeuronAI\MCP\McpConnector::make()', 'httpClient'), 30],
            [sprintf(self::CURL, 'new NeuronAI\HttpClient\Curl\CurlHttpClient'), 35],
            [sprintf(self::AMP, 'new NeuronAI\HttpClient\Amp\AmpHttpClient'), 36],
            [sprintf(self::FORK, 'NeuronAI\Agent\Agent::parallelToolCalls()'), 41],
            [sprintf(self::FORK, 'NeuronAI\Agent\Agent::parallelToolCalls()'), 42],
            [sprintf(self::FORK, 'NeuronAI\Agent\Agent::parallelToolCalls()'), 43],
            [sprintf(self::STORAGE, 'new NeuronAI\Workflow\Persistence\FilePersistence'), 48],
            [sprintf(self::STORAGE, 'new NeuronAI\Workflow\Persistence\DatabasePersistence'), 49],
            [sprintf(self::STORAGE, 'new NeuronAI\Chat\History\FileMessageStore'), 50],
            [sprintf(self::STORAGE, 'new NeuronAI\Chat\History\SQLMessageStore'), 51],
            [sprintf(self::SERIALIZER, 'new NeuronAI\Workflow\Persistence\PhpSerializer'), 52],
            [sprintf(self::MCP, 'new NeuronAI\MCP\StdioTransport'), 57],
            [sprintf(self::MCP, 'new NeuronAI\MCP\SseHttpTransport'), 58],
            [sprintf(self::MCP, 'new NeuronAI\MCP\McpConnector'), 59],
            [sprintf(self::MCP, 'NeuronAI\MCP\McpConnector::make()'), 60],
            [sprintf(self::USAGE, 'NeuronAI\Evaluation\Usage\UsageMeter::watch()'), 65],
            [sprintf(self::USAGE, 'new NeuronAI\Evaluation\Usage\UsageMeter'), 66],
        ]);
    }

    public function test_passes_an_explicit_client_disabled_parallel_calls_and_what_it_cannot_judge(): void
    {
        $this->analyse([__DIR__ . '/Fixtures/WorkerSafeNeuronCalls.php'], []);
    }
}
