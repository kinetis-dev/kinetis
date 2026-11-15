<?php

declare(strict_types=1);

namespace Kinetis\Neuron\Tests\Bedrock;

use Kinetis\AwsSigV4\SignedTransport;
use Kinetis\AwsSigV4\SigV4SigningClient;
use Kinetis\Neuron\Bedrock\BedrockProvider;
use Kinetis\Neuron\Bedrock\EventStreamDecoder;
use Kinetis\Neuron\Tests\Fixtures\Bedrock\StaticCredentials;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

use function Kinetis\Async\concurrently;

/**
 * The provider over a real SignedTransport against a stand-in Bedrock
 * served by `php -S` in another process.
 */
final class BedrockServerTest extends TestCase
{
    private const string HOST = '127.0.0.1:8195';

    private const string OTHER_HOST = '127.0.0.1:8196';

    /** @var list<resource> */
    private static array $servers = [];

    /** @var array<string, SigV4SigningClient> */
    private static array $clients = [];

    /** @var list<string> */
    private array $markFiles = [];

    public static function setUpBeforeClass(): void
    {
        foreach ([self::HOST, self::OTHER_HOST] as $host) {
            // A worker keeps writing to a client that left until it notices,
            // and a busy worker accepts nothing.
            self::$servers[] = proc_open(
                ['php', '-S', $host, \dirname(__DIR__) . '/Fixtures/bedrock-server.php'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                [...getenv(), 'PHP_CLI_SERVER_WORKERS' => '8'],
            );

            $deadline = microtime(true) + 5.0;

            while (($socket = @stream_socket_client('tcp://' . $host, timeout: 0.1)) === false) {
                if (microtime(true) > $deadline) {
                    self::fail("The fixture server on {$host} never started accepting connections.");
                }

                usleep(20_000);
            }

            fclose($socket);
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$servers as $server) {
            proc_terminate($server);
            proc_close($server);
        }

        self::$servers = [];
        self::$clients = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->markFiles as $file) {
            @unlink($file);
        }
    }

    public function test_two_chats_overlap_and_the_loop_keeps_running(): void
    {
        // A `php -S` worker can accept both connections and then serve them
        // one after the other, so each call has a server of its own.
        $chat = fn (string $host): \Closure => fn (): ?string => $this->provider('slow', host: $host)
            ->chat(new UserMessage('ping'))->message()->getContent();

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

        self::assertSame(['pong', 'pong'], $answers);
        // Each answer takes the stand-in one second; one after the other is two.
        self::assertLessThan(1.6, microtime(true) - $start);
        self::assertGreaterThanOrEqual(10, $ticks, 'The event loop stalled while the calls were in flight.');
    }

    public function test_a_stream_written_in_pieces_yields_its_text_as_it_arrives(): void
    {
        $start = microtime(true);
        $stream = $this->provider('split')->stream(new UserMessage('ping'));
        $text = [];
        $firstAt = null;

        foreach ($stream as $chunk) {
            if ($chunk instanceof TextChunk) {
                $firstAt ??= microtime(true) - $start;
                $text[] = $chunk->content;
            }
        }

        $message = $stream->getReturn()->message();

        self::assertSame(['Hel', 'lo'], $text);
        self::assertSame('Hello', $message->getContent());
        self::assertSame('end_turn', $message->stopReason());
        self::assertSame([4, 2], [$message->getUsage()?->inputTokens, $message->getUsage()?->outputTokens]);
        // The stand-in spends 400 ms writing; a buffered body arrives whole at the end.
        self::assertLessThan(microtime(true) - $start - 0.15, $firstAt);
    }

    public function test_leaving_a_stream_early_closes_the_connection(): void
    {
        $mark = $this->markFile();
        foreach ($this->provider('until-abort')->stream(new UserMessage($mark)) as $chunk) {
            break;
        }

        self::assertStringContainsString('aborted after', $this->awaitMark($mark));
    }

    public function test_a_stream_past_the_ceiling_is_refused_and_closed(): void
    {
        $mark = $this->markFile();

        try {
            iterator_to_array($this->provider('until-abort', 1024)->stream(new UserMessage($mark)), false);
            self::fail('The stream passed its ceiling.');
        } catch (ProviderException $e) {
            self::assertSame(\sprintf(EventStreamDecoder::TOO_LARGE, 1024), $e->getMessage());
        }

        self::assertStringContainsString('aborted after', $this->awaitMark($mark));
    }

    public function test_a_buffered_answer_past_the_ceiling_is_refused_and_closed(): void
    {
        $mark = $this->markFile();

        try {
            $this->provider('until-abort', 16 * 1024)->chat(new UserMessage($mark));
            self::fail('The answer passed its ceiling.');
        } catch (ProviderException $e) {
            self::assertSame(\sprintf(EventStreamDecoder::TOO_LARGE, 16 * 1024), $e->getMessage());
        }

        self::assertStringContainsString('aborted after', $this->awaitMark($mark));
    }

    private function provider(string $model, int $maxResponseBytes = 8 * 1024 * 1024, string $host = self::HOST): BedrockProvider
    {
        self::$clients[$host] ??= new SigV4SigningClient(
            'http://' . $host,
            'us-east-1',
            'bedrock',
            new StaticCredentials(),
            transport: SignedTransport::create(['timeout' => 10, 'max_duration' => 10]),
        );

        return new BedrockProvider(self::$clients[$host], $model, maxResponseBytes: $maxResponseBytes);
    }

    private function markFile(): string
    {
        return $this->markFiles[] = sys_get_temp_dir() . '/kinetis-bedrock-' . bin2hex(random_bytes(6));
    }

    /** The mark once the stand-in has written its outcome, or what it holds after three seconds. */
    private function awaitMark(string $mark): string
    {
        $deadline = microtime(true) + 3.0;

        while (!str_contains((string) @file_get_contents($mark), "\n") && microtime(true) < $deadline) {
            $suspension = EventLoop::getSuspension();
            EventLoop::delay(0.05, static fn () => $suspension->resume());
            $suspension->suspend();
        }

        return (string) @file_get_contents($mark);
    }
}
