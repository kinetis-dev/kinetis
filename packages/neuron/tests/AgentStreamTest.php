<?php

declare(strict_types=1);

namespace Kinetis\Neuron\Tests;

use Closure;
use Generator;
use Kinetis\Neuron\AgentStream;
use Kinetis\Neuron\NeuronHttpClient;
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;
use Kinetis\RevoltHttpClient\Http;
use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\StoppableHttpClient;
use NeuronAI\Providers\OpenAILike;
use NeuronAI\Workflow\Streaming\Adapter\StreamAdapterInterface;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\Streaming\SSEEncoder;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface as SymfonyHttpClient;
use Throwable;

/**
 * Agent runs against a real local stand-in provider, emitted into an
 * output buffer that records every flush.
 */
final class AgentStreamTest extends TestCase
{
    private const string HOST = '127.0.0.1:8194';

    private const string BASE = 'http://' . self::HOST . '/v1';

    /** @var resource|null */
    private static $server = null;

    private static ?SymfonyHttpClient $transport = null;

    /** @var list<string> */
    private array $markFiles = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = proc_open(
            ['php', '-S', self::HOST, __DIR__ . '/Fixtures/provider-server.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [...getenv(), 'PHP_CLI_SERVER_WORKERS' => '4'],
        );

        $deadline = microtime(true) + 5.0;

        while (($socket = @stream_socket_client('tcp://' . self::HOST, timeout: 0.1)) === false) {
            if (microtime(true) > $deadline) {
                self::fail('The fixture server never started accepting connections.');
            }

            usleep(20_000);
        }

        fclose($socket);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->markFiles as $file) {
            @unlink($file);
        }
    }

    public function test_the_response_carries_the_adapter_headers_and_writes_each_event_as_one_flushed_frame(): void
    {
        $adapter = new VercelAIAdapter();
        $events = [];
        $run = self::tap($this->agent('stub-model', $adapter)->stream(new UserMessage('ping')), $events);

        $response = new AgentStream($adapter)->response($run);

        self::assertSame(200, $response->getStatusCode());

        foreach ($adapter->getHeaders() as $name => $value) {
            self::assertSame([$value], $response->getHeader($name));
        }

        $flushes = self::emit($response->getEmitter());

        self::assertSame(array_map(SSEEncoder::frame(...), $events), $flushes);
        self::assertSame(
            ['start', 'text-start', 'text-delta', 'text-delta', 'text-end', 'finish'],
            array_map(static fn (ProtocolEvent $event): string => $event->type, $events),
        );
    }

    public function test_an_ag_ui_run_streams_with_its_own_headers(): void
    {
        $adapter = new AGUIAdapter('thread-1');
        $events = [];
        $run = self::tap($this->agent('stub-model', $adapter)->stream(new UserMessage('ping')), $events);

        $response = new AgentStream($adapter)->response($run);

        self::assertSame(['text/event-stream'], $response->getHeader('Content-Type'));
        $flushes = self::emit($response->getEmitter());
        self::assertSame(array_map(SSEEncoder::frame(...), $events), $flushes);
        self::assertSame('RUN_FINISHED', end($events)->type);
    }

    public function test_a_run_that_fails_mid_answer_ends_with_one_error_frame_and_the_failure_propagates(): void
    {
        $adapter = new VercelAIAdapter();
        $events = [];
        $run = self::tap($this->agent('cut', $adapter)->stream(new UserMessage('ping')), $events);
        $flushes = [];

        $failure = self::failure(static function () use ($adapter, $run, &$flushes): void {
            self::emit(new AgentStream($adapter)->response($run)->getEmitter(), $flushes);
        });

        self::assertInstanceOf(ProviderException::class, $failure);
        self::assertSame(array_map(SSEEncoder::frame(...), $events), $flushes);
        self::assertSame(self::errorFrame(), end($flushes));
        self::assertCount(1, array_filter($flushes, static fn (string $frame): bool => $frame === self::errorFrame()));
    }

    public function test_a_failure_the_run_did_not_frame_gets_the_adapter_error_frame_and_propagates(): void
    {
        $adapter = new VercelAIAdapter();
        $run = (static function () use ($adapter): Generator {
            yield from $adapter->transform(new TextChunk('msg_1', 'Hel'));

            throw new RuntimeException('not framed');
        })();
        $flushes = [];

        $failure = self::failure(static function () use ($adapter, $run, &$flushes): void {
            self::emit(new AgentStream($adapter)->response($run)->getEmitter(), $flushes);
        });

        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertSame('not framed', $failure->getMessage());
        self::assertSame(SSEEncoder::frame(new ProtocolEvent('text-end', ['id' => self::decode($flushes[1])['id']])), $flushes[3]);
        self::assertSame(self::errorFrame(), $flushes[4]);
        self::assertCount(5, $flushes);
    }

    public function test_a_stop_ends_the_answer_and_cancels_the_provider_connection(): void
    {
        $mark = $this->markFile();
        $adapter = new VercelAIAdapter();
        $flushes = [];
        $client = new StoppableHttpClient($this->client(), static function () use (&$flushes): bool {
            return count($flushes) >= 4;
        });
        $run = $this->agent('until-abort', $adapter, $client, ['mark' => $mark])->stream(new UserMessage('ping'));

        self::emit(new AgentStream($adapter)->response($run)->getEmitter(), $flushes);

        // start, text-start, two deltas: the stop is asked before the next event is read.
        self::assertSame(['start', 'text-start', 'text-delta', 'text-delta', 'text-end', 'finish'], array_map(
            static fn (string $frame): string => self::decode($frame)['type'],
            $flushes,
        ));

        $state = $run->getReturn();
        self::assertInstanceOf(AgentState::class, $state);
        $answer = $state->getMessage();
        self::assertInstanceOf(AssistantMessage::class, $answer);
        self::assertSame(StoppableHttpClient::STOP_REASON, $answer->stopReason());
        self::assertSame('w0 w1 ', $answer->getContent());

        // The provider writes for five seconds unless its connection is
        // cancelled. The transport closes the socket from callbacks it
        // queues on the event loop, so the wait turns the loop.
        $deadline = microtime(true) + 2.0;

        while (!str_contains((string) @file_get_contents($mark), 'aborted after') && microtime(true) < $deadline) {
            $suspension = EventLoop::getSuspension();
            EventLoop::delay(0.02, $suspension->resume(...));
            $suspension->suspend();
        }

        self::assertStringContainsString('aborted after', (string) @file_get_contents($mark));
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function agent(
        string $model,
        StreamAdapterInterface $adapter,
        ?HttpClientInterface $client = null,
        array $parameters = [],
    ): Agent {
        return Agent::make()
            ->setAiProvider(new OpenAILike(self::BASE, 'test-key', $model, $parameters, httpClient: $client ?? $this->client()))
            ->setThreadId('thread-1')
            ->setStreamAdapter(static fn (): StreamAdapterInterface => $adapter);
    }

    private function client(): NeuronHttpClient
    {
        return new NeuronHttpClient(new Http(self::$transport ??= AmpHttpClientFactory::create())->withTimeout(10), self::BASE);
    }

    private function markFile(): string
    {
        return $this->markFiles[] = sys_get_temp_dir() . '/kinetis-neuron-' . bin2hex(random_bytes(6));
    }

    /**
     * Runs $emitter inside an output buffer and returns what each flush
     * carried, also kept in $flushes as it happens. Bytes written but never
     * flushed fail the test.
     *
     * @param list<string> $flushes
     * @return list<string>
     */
    private static function emit(Closure $emitter, array &$flushes = []): array
    {
        $flushes = [];
        $unflushed = '';

        ob_start(static function (string $buffer, int $phase) use (&$flushes, &$unflushed): string {
            if (($phase & PHP_OUTPUT_HANDLER_FLUSH) !== 0) {
                $flushes[] = $buffer;
            } else {
                $unflushed .= $buffer;
            }

            return '';
        });

        try {
            $emitter();
        } finally {
            ob_end_clean();
            self::assertSame('', $unflushed, 'Output was written without a flush.');
        }

        return $flushes;
    }

    /**
     * Passes $run on unchanged and records every event it yields.
     *
     * @param list<ProtocolEvent> $events
     * @return Generator<int, mixed, mixed, mixed>
     */
    private static function tap(Generator $run, array &$events): Generator
    {
        foreach ($run as $key => $event) {
            self::assertInstanceOf(ProtocolEvent::class, $event);
            $events[] = $event;

            yield $key => $event;
        }

        return $run->getReturn();
    }

    private static function failure(Closure $call): Throwable
    {
        try {
            $call();
        } catch (Throwable $failure) {
            return $failure;
        }

        self::fail('The emitter was expected to throw.');
    }

    private static function errorFrame(): string
    {
        return SSEEncoder::frame(new ProtocolEvent('error', ['errorText' => 'The run failed.']));
    }

    /** @return array<string, mixed> */
    private static function decode(string $frame): array
    {
        $data = json_decode(substr($frame, strlen('data: ')), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }
}
