<?php

declare(strict_types=1);

namespace Kinetis\Neuron\Tests;

use Kinetis\Neuron\NeuronHttpClient;
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;
use Kinetis\RevoltHttpClient\Exception\HttpFailure;
use Kinetis\RevoltHttpClient\Exception\HttpRequestException;
use Kinetis\RevoltHttpClient\Http;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\QdrantVectorStore;
use NeuronAI\RAG\VectorStore\SearchRequest;
use PHPUnit\Framework\TestCase;

/**
 * Neuron's Qdrant store over the bridge, against the stand-in collection
 * of the local provider server. Qdrant creates and deletes a collection
 * at the collection URL itself, so the client's base URL must be the
 * server's origin.
 */
final class VectorStoreTest extends TestCase
{
    private const string ORIGIN = 'http://127.0.0.1:8194';

    /** @var resource|null */
    private static $server = null;

    private string $collection;

    public static function setUpBeforeClass(): void
    {
        self::$server = proc_open(
            ['php', '-S', '127.0.0.1:8194', __DIR__ . '/Fixtures/provider-server.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        $deadline = microtime(true) + 5.0;

        while (($socket = @stream_socket_client('tcp://127.0.0.1:8194', timeout: 0.1)) === false) {
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

    protected function setUp(): void
    {
        $this->collection = 'kinetis-neuron-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        @unlink($this->received());
    }

    public function test_every_store_request_reaches_the_server_through_a_client_pinned_at_its_origin(): void
    {
        $store = $this->store(self::ORIGIN);
        $document = new Document('stored text');
        $document->setEmbedding([0.1, 0.2]);

        $store->addDocuments([$document]);
        $found = [...$store->search(new SearchRequest([0.1, 0.2]))];
        $store->destroy();

        self::assertCount(1, $found);
        self::assertSame('stored text', $found[0]->getContent());
        self::assertSame(0.9, $found[0]->getScore());

        $path = "/collections/{$this->collection}";
        self::assertSame(
            "GET {$path}/exists\nPUT {$path}\nPUT {$path}/points\nPOST {$path}/points/query\nDELETE {$path}\n",
            file_get_contents($this->received()),
        );
    }

    public function test_a_client_pinned_at_the_collection_url_refuses_to_delete_the_collection(): void
    {
        $store = $this->store(self::ORIGIN . "/collections/{$this->collection}");

        try {
            $store->destroy();
            self::fail('Deleting the collection was expected to fail.');
        } catch (HttpException $failure) {
            $previous = $failure->getPrevious();
            self::assertInstanceOf(HttpRequestException::class, $previous);
            self::assertSame(HttpFailure::InvalidRequest, $previous->category);
        }

        self::assertFileDoesNotExist($this->received());
    }

    private function store(string $baseUrl): QdrantVectorStore
    {
        return new QdrantVectorStore(
            self::ORIGIN . "/collections/{$this->collection}",
            dimension: 2,
            httpClient: new NeuronHttpClient(new Http(AmpHttpClientFactory::create())->withTimeout(10), $baseUrl),
        );
    }

    /** The file the stand-in server appends each request it receives to. */
    private function received(): string
    {
        return sys_get_temp_dir() . "/{$this->collection}";
    }
}
