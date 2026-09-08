<?php

declare(strict_types=1);

namespace Kinetis\SearchOpenSearch\Tests;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Search\Exception\SearchConfigurationException;
use Kinetis\Search\SearchClient;
use Kinetis\SearchOpenSearch\OpenSearchClient;
use Kinetis\SearchOpenSearch\PackageBootstrap;
use OpenSearch\Client;
use OpenSearch\EndpointFactory;
use OpenSearch\TransportFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class PackageBootstrapTest extends TestCase
{
    public function test_no_host_configured_binds_nothing(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config([]));

        self::assertFalse($app->has(Client::class));
        self::assertFalse($app->has(SearchClient::class));
    }

    /**
     * Constructing the client opens no connection, so this asserts the
     * binding without needing a live cluster.
     */
    public function test_a_configured_host_binds_the_engine_client(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config([
            'SEARCH_OPENSEARCH_HOST' => 'https://localhost:9200',
        ]));
        $app->boot();

        self::assertInstanceOf(Client::class, $app->get(Client::class));
    }

    public function test_a_configured_host_also_binds_the_engine_neutral_client_over_it(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config([
            'SEARCH_OPENSEARCH_HOST' => 'https://localhost:9200',
        ]));
        $app->boot();

        self::assertInstanceOf(OpenSearchClient::class, $app->get(SearchClient::class));
    }

    /**
     * One client for the worker, deliberately: OpenSearch's HttpTransport
     * keeps nothing between calls and its EndpointFactory builds a fresh
     * endpoint per call, so nothing request-owned can survive in it. The
     * Elasticsearch package binds a client per resolution instead,
     * because its transport keeps the last request and response.
     */
    public function test_one_client_serves_the_whole_worker(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config([
            'SEARCH_OPENSEARCH_HOST' => 'https://localhost:9200',
        ]));
        $app->boot();

        self::assertSame($app->get(Client::class), $app->get(Client::class));
        self::assertSame($app->get(SearchClient::class), $app->get(SearchClient::class));
    }

    public function test_the_client_is_built_while_registering_not_on_first_search(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config([
            'SEARCH_OPENSEARCH_HOST' => 'https://localhost:9200',
        ]));

        // Registered as an instance, so it exists before boot() and
        // before anything resolves it.
        self::assertTrue($app->has(Client::class));
    }

    public function test_configuration_a_client_cannot_be_built_from_fails_while_registering(): void
    {
        $this->expectException(SearchConfigurationException::class);
        $this->expectExceptionMessage('SEARCH_OPENSEARCH_PLAINTEXT');
        new PackageBootstrap()->register(new AppScope(), new Config([
            'SEARCH_OPENSEARCH_HOST' => 'http://localhost:9200',
        ]));
    }

    public function test_an_application_can_replace_the_binding_before_boot(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config([
            'SEARCH_OPENSEARCH_HOST' => 'https://localhost:9200',
        ]));

        $own = new Client(
            new TransportFactory()->setHttpClient($this->neverCalledClient())->create(),
            new EndpointFactory(),
        );
        $app->instance(Client::class, $own);
        $app->boot();

        self::assertSame($own, $app->get(Client::class));
    }

    private function neverCalledClient(): ClientInterface
    {
        return new class implements ClientInterface {
            #[\Override]
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('never called by this test');
            }
        };
    }
}
