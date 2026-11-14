<?php

declare(strict_types=1);

namespace Kinetis\Neuron\Tests;

use Closure;
use Kinetis\Neuron\NeuronHttpClient;
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;
use Kinetis\RevoltHttpClient\Exception\HttpFailure;
use Kinetis\RevoltHttpClient\Exception\HttpRequestException;
use Kinetis\RevoltHttpClient\Http;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Providers\OpenAILike;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function Kinetis\Async\concurrently;

/**
 * The bridge against two real local servers running the same stand-in
 * provider. A client pinned to the first must never reach the second.
 */
final class NeuronHttpClientTest extends TestCase
{
    private const string HOST = '127.0.0.1:8192';

    private const string OTHER_HOST = '127.0.0.1:8193';

    private const string BASE = 'http://' . self::HOST . '/v1';

    /** @var list<resource> */
    private static array $servers = [];

    private static ?HttpClientInterface $transport = null;

    /** @var list<string> */
    private array $markFiles = [];

    public static function setUpBeforeClass(): void
    {
        foreach ([self::HOST, self::OTHER_HOST] as $host) {
            // A worker keeps sleeping through a request the client has
            // left (`/stall` for three seconds, `/slow/2000` for two), and a
            // busy worker accepts nothing. Enough workers that the next
            // request, repeated runs included, always finds an idle one.
            self::$servers[] = proc_open(
                ['php', '-S', $host, __DIR__ . '/Fixtures/provider-server.php'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                [...getenv(), 'PHP_CLI_SERVER_WORKERS' => '8'],
            );

            self::awaitListening($host);
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$servers as $server) {
            proc_terminate($server);
            proc_close($server);
        }

        self::$servers = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->markFiles as $file) {
            @unlink($file);
        }
    }

    public function test_two_provider_calls_overlap_and_the_loop_keeps_running(): void
    {
        // A `php -S` worker can accept both connections and then serve them
        // one after the other, so each call has a server of its own.
        $http = new Http(self::transport())->withTimeout(10);
        $chat = static fn (string $host): Closure => static fn (): ?string => new OpenAILike(
            "http://{$host}/v1",
            'test-key',
            'stub-model',
            httpClient: new NeuronHttpClient($http, "http://{$host}/v1"),
        )->chat(new UserMessage('ping'))->message()->getContent();

        $ticks = 0;
        $timer = EventLoop::repeat(0.05, static function () use (&$ticks): void {
            ++$ticks;
        });

        $start = microtime(true);

        try {
            $answers = concurrently([$chat(self::HOST), $chat(self::OTHER_HOST)]);
        } finally {
            EventLoop::cancel($timer);
        }

        $elapsed = microtime(true) - $start;

        self::assertSame(['pong', 'pong'], $answers);
        // Each answer takes the stub one second; one after the other is two.
        self::assertLessThan(1.6, $elapsed);
        self::assertGreaterThanOrEqual(10, $ticks, 'The event loop stalled while the calls were in flight.');
    }

    public function test_a_provider_streams_its_answer_through_the_bridge(): void
    {
        $provider = new OpenAILike(self::BASE, 'test-key', 'stub-model', httpClient: $this->client());
        $text = '';

        foreach ($provider->stream(new UserMessage('ping')) as $chunk) {
            if ($chunk instanceof TextChunk) {
                $text .= $chunk->content;
            }
        }

        self::assertSame('Hello', $text);
    }

    public function test_the_first_line_arrives_before_the_body_ends(): void
    {
        $start = microtime(true);
        $stream = $this->client()->stream(HttpRequest::get(self::BASE . '/lines'));

        self::assertSame("data: one\n", $stream->readLine());
        // The stub spends 0.9 s sending; a buffered body would arrive whole at the end.
        self::assertLessThan(0.5, microtime(true) - $start);
        self::assertFalse($stream->eof());

        // `data: tw` and `o\n` arrive as two pieces 300 ms apart.
        self::assertSame("data: two\n", $stream->readLine());
        self::assertGreaterThan(0.5, microtime(true) - $start);
        self::assertSame('last', $stream->readLine());
        self::assertTrue($stream->eof());
        self::assertSame('', $stream->readLine());
        self::assertSame('', $stream->read(10));
    }

    public function test_read_returns_at_most_the_requested_length_and_keeps_the_rest(): void
    {
        $stream = $this->client()->stream(HttpRequest::get(self::BASE . '/lines'));

        self::assertSame('data', $stream->read(4));
        self::assertFalse($stream->eof());
        self::assertSame(': one', $stream->read(5));
        self::assertSame("\n", $stream->read(100));
        self::assertSame('data: tw', $stream->read(100));
        self::assertSame("o\n", $stream->readLine());
        self::assertSame('la', $stream->read(2));
        self::assertSame('st', $stream->read(2));
        self::assertFalse($stream->eof(), 'The end is known only once a read finds it.');
        self::assertSame('', $stream->read(2));
        self::assertTrue($stream->eof());
    }

    public function test_close_mid_stream_ends_the_transport_read(): void
    {
        $mark = $this->markFile();
        $stream = $this->client()->stream(HttpRequest::get(self::BASE . "/until-abort?mark={$mark}"));

        self::assertSame("data: 0\n", $stream->readLine());

        $stream->close();

        self::assertTrue($stream->eof());
        self::assertSame('', $stream->readLine());

        // $stream is still held, so only close() can have cancelled the
        // response the server is writing to.
        $deadline = microtime(true) + 3.0;

        while (!str_contains((string) @file_get_contents($mark), 'aborted after') && microtime(true) < $deadline) {
            self::pause(0.05);
        }

        self::assertStringContainsString('aborted after', (string) file_get_contents($mark));
    }

    public function test_an_oversized_body_fails_as_a_network_error(): void
    {
        $client = $this->client(new Http(self::transport())->withMaxResponseBytes(4096));

        $failure = $this->failure(static fn () => $client->request(HttpRequest::get(self::BASE . '/bytes/65536')));
        self::assertCategory(HttpFailure::ResponseTooLarge, $failure);
        self::assertStringStartsWith('Network error during GET', $failure->getMessage());

        $stream = $client->stream(HttpRequest::get(self::BASE . '/bytes/65536'));
        $failure = $this->failure(static function () use ($stream): void {
            while (!$stream->eof()) {
                $stream->read(1024);
            }
        });
        self::assertCategory(HttpFailure::ResponseTooLarge, $failure);
    }

    public function test_a_stalled_stream_ends_at_the_deadline_after_one_request(): void
    {
        $mark = $this->markFile();
        $client = $this->client(new Http(self::transport())->withTimeout(0.5)->withRetries(3));
        $start = microtime(true);
        $stream = $client->stream(new HttpRequest(HttpMethod::POST, self::BASE . "/stall?mark={$mark}", body: ['stream' => true]));

        self::assertSame("data: first\n", $stream->readLine());

        $failure = $this->failure(static fn () => $stream->readLine());

        self::assertCategory(HttpFailure::Timeout, $failure);
        self::assertLessThan(1.5, microtime(true) - $start);
        // withRetries() never applies to a POST.
        self::assertSame("POST /v1/stall\n", file_get_contents($mark));
    }

    public function test_an_error_status_carries_the_status_and_the_body(): void
    {
        foreach ([422, 503] as $status) {
            $request = HttpRequest::post(self::BASE . "/status/{$status}", ['model' => 'stub']);

            foreach (['request', 'stream'] as $method) {
                $failure = $this->failure(fn () => $this->client()->{$method}($request));

                self::assertNotNull($failure->response, "{$method}() reported {$status} without a response.");
                self::assertSame($status, $failure->response->statusCode);
                self::assertSame('{"error":"upstream refused"}', $failure->response->body);
                self::assertStringStartsWith("HTTP {$status} error during POST", $failure->getMessage());
            }
        }
    }

    public function test_a_uri_on_another_origin_is_refused_before_it_is_sent(): void
    {
        $mark = $this->markFile();
        $request = HttpRequest::get('http://' . self::OTHER_HOST . "/record?mark={$mark}", ['Authorization' => 'Bearer secret']);

        foreach (['request', 'stream'] as $method) {
            $failure = $this->failure(fn () => $this->client()->{$method}($request));

            self::assertCategory(HttpFailure::InvalidRequest, $failure);
        }

        // The other origin is listening, so a request sent there would have left this mark.
        self::assertFileDoesNotExist($mark);
        self::assertSame('recorded', new Http(self::transport())->get('http://' . self::OTHER_HOST . '/record')->body());
    }

    public function test_a_uri_under_the_base_path_but_outside_it_is_refused(): void
    {
        $failure = $this->failure(fn () => $this->client()->request(HttpRequest::get('http://' . self::HOST . '/v10/echo')));

        self::assertCategory(HttpFailure::InvalidRequest, $failure);
    }

    public function test_the_base_url_itself_is_refused_as_an_absolute_url(): void
    {
        $failure = $this->failure(fn () => $this->client()->request(HttpRequest::get(self::BASE)));

        self::assertCategory(HttpFailure::InvalidRequest, $failure);
        self::assertStringContainsString('With a base URL, a request URL must be a path relative to it', $failure->getMessage());
    }

    public function test_an_array_body_is_sent_as_json_and_a_string_body_raw(): void
    {
        $client = $this->client();

        $json = $client->request(new HttpRequest(
            HttpMethod::POST,
            self::BASE . '/echo',
            ['Authorization' => 'Bearer key', 'X-Custom' => 'one'],
            ['model' => 'stub', 'messages' => [['role' => 'user', 'content' => 'hi']]],
        ))->json();

        self::assertSame('POST', $json['method']);
        self::assertSame('application/json', $json['contentType']);
        self::assertSame('Bearer key', $json['authorization']);
        self::assertSame('one', $json['custom']);
        self::assertSame(['model' => 'stub', 'messages' => [['role' => 'user', 'content' => 'hi']]], json_decode($json['body'], true));

        $raw = $client->request(new HttpRequest(HttpMethod::PUT, 'echo', ['Content-Type' => 'text/plain'], 'a raw body'))->json();

        self::assertSame('PUT', $raw['method']);
        self::assertSame('text/plain', $raw['contentType']);
        self::assertSame('a raw body', $raw['body']);

        $none = $client->request(HttpRequest::get(self::BASE . '/echo'))->json();

        self::assertSame('', $none['body']);
    }

    public function test_the_request_timeout_overrides_the_client_timeout(): void
    {
        $client = $this->client(new Http(self::transport())->withTimeout(5));

        self::assertSame('done', $client->request(HttpRequest::get(self::BASE . '/slow/300'))->body);

        $request = new HttpRequest(HttpMethod::GET, self::BASE . '/slow/2000', timeout: 0.3);
        $start = microtime(true);
        $failure = $this->failure(static fn () => $client->request($request));

        self::assertCategory(HttpFailure::Timeout, $failure);
        self::assertLessThan(1.0, microtime(true) - $start);
    }

    public function test_a_multipart_request_is_refused(): void
    {
        $file = fopen('php://memory', 'r');
        $request = new HttpRequest(HttpMethod::POST, self::BASE . '/echo', body: ['file' => $file]);

        foreach (['request', 'stream'] as $method) {
            $failure = $this->failure(fn () => $this->client()->{$method}($request));

            self::assertNull($failure->getPrevious());
            self::assertStringEndsWith('Multipart request bodies are not supported.', $failure->getMessage());
        }
    }

    public function test_the_response_carries_status_body_and_headers(): void
    {
        $response = $this->client()->request(HttpRequest::get(self::BASE . '/slow/0'));

        self::assertSame(200, $response->statusCode);
        self::assertSame('done', $response->body);
        self::assertNotNull($response->header('Content-Type'));
    }

    private function client(?Http $http = null): NeuronHttpClient
    {
        return new NeuronHttpClient($http ?? new Http(self::transport())->withTimeout(10), self::BASE);
    }

    private static function transport(): HttpClientInterface
    {
        return self::$transport ??= AmpHttpClientFactory::create();
    }

    private function markFile(): string
    {
        return $this->markFiles[] = sys_get_temp_dir() . '/kinetis-neuron-' . bin2hex(random_bytes(6));
    }

    /** @param callable(): mixed $call */
    private function failure(callable $call): HttpException
    {
        try {
            $call();
        } catch (HttpException $failure) {
            return $failure;
        }

        self::fail('The call was expected to raise an HttpException.');
    }

    private static function assertCategory(HttpFailure $category, HttpException $failure): void
    {
        $previous = $failure->getPrevious();

        self::assertInstanceOf(HttpRequestException::class, $previous);
        self::assertSame($category, $previous->category);
        self::assertNull($failure->response);
        self::assertStringStartsWith('Network error during', $failure->getMessage());
    }

    private static function pause(float $seconds): void
    {
        $suspension = EventLoop::getSuspension();
        EventLoop::delay($seconds, static fn () => $suspension->resume());
        $suspension->suspend();
    }

    private static function awaitListening(string $host): void
    {
        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client('tcp://' . $host, timeout: 0.1);

            if ($socket !== false) {
                fclose($socket);

                return;
            }

            usleep(20_000);
        }

        self::fail("The fixture server on {$host} never started accepting connections.");
    }
}
