<?php

declare(strict_types=1);

namespace Kinetis\SearchOpenSearch\Tests;

use Closure;
use Kinetis\Config\Config;
use Kinetis\SearchOpenSearch\Exception\OpenSearchNetworkException;
use Kinetis\SearchOpenSearch\OpenSearchClientFactory;
use Kinetis\SearchOpenSearch\OpenSearchHttpClient;
use Nyholm\Psr7\Request;
use OpenSearch\Client;
use OpenSearch\EndpointFactory;
use OpenSearch\Exception\NotFoundHttpException;
use OpenSearch\TransportFactory;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OpenSearchHttpClientTest extends TestCase
{
    public function test_a_successful_response_is_complete_before_send_request_returns(): void
    {
        $transport = new MockHttpClient(new MockResponse(
            '{"took":3,"hits":{"total":{"value":1}}}',
            ['response_headers' => ['content-type' => 'application/json', 'x-opensearch' => 'yes']],
        ));

        $response = new OpenSearchHttpClient($transport)->sendRequest(
            new Request('GET', 'https://localhost:9200/articles/_search'),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"took":3,"hits":{"total":{"value":1}}}', $response->getBody()->getContents());
        self::assertSame('application/json', $response->getHeaderLine('content-type'));
        self::assertSame('yes', $response->getHeaderLine('x-opensearch'));
    }

    /**
     * A 4xx is a response, not a transport failure: it reaches the caller
     * whole so the official client can map it.
     */
    public function test_a_4xx_response_is_buffered_and_passed_through(): void
    {
        $transport = new MockHttpClient(new MockResponse(
            '{"error":{"type":"index_not_found_exception"},"status":404}',
            ['http_code' => 404, 'response_headers' => ['content-type' => 'application/json']],
        ));

        $response = new OpenSearchHttpClient($transport)->sendRequest(
            new Request('GET', 'https://localhost:9200/missing/_doc/1'),
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('{"error":{"type":"index_not_found_exception"},"status":404}', (string) $response->getBody());
        self::assertSame('application/json', $response->getHeaderLine('content-type'));
    }

    public function test_the_official_client_still_owns_status_mapping(): void
    {
        $client = $this->openSearchClientOver(new MockHttpClient(new MockResponse(
            '{"error":{"type":"index_not_found_exception","reason":"no such index"},"status":404}',
            ['http_code' => 404, 'response_headers' => ['content-type' => 'application/json']],
        )));

        $this->expectException(NotFoundHttpException::class);
        $client->get(['index' => 'missing', 'id' => '1']);
    }

    public function test_the_official_client_reads_a_success_body_through_the_adapter(): void
    {
        $client = $this->openSearchClientOver(new MockHttpClient(new MockResponse(
            '{"_source":{"title":"Kinetis"}}',
            ['response_headers' => ['content-type' => 'application/json']],
        )));

        $document = $client->get(['index' => 'articles', 'id' => '1']);

        self::assertSame('Kinetis', $document['_source']['title']);
    }

    public function test_the_request_reaches_the_transport_unchanged(): void
    {
        $seen = [];
        $transport = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('{}', ['response_headers' => ['content-type' => 'application/json']]);
        });

        new OpenSearchHttpClient($transport)->sendRequest(
            new Request(
                'POST',
                'https://localhost:9200/articles/_search',
                ['Content-Type' => 'application/json'],
                '{"query":{"match_all":{}}}',
            ),
        );

        self::assertSame('POST', $seen['method']);
        self::assertSame('https://localhost:9200/articles/_search', $seen['url']);
        self::assertSame('{"query":{"match_all":{}}}', $seen['options']['body']);
        self::assertContains('Content-Type: application/json', $seen['options']['headers']);
    }

    public function test_a_connection_failure_arrives_as_a_network_exception_carrying_the_request(): void
    {
        $transport = new MockHttpClient(new MockResponse('', ['error' => 'connection refused']));
        $request = new Request('GET', 'https://localhost:9200/articles/_search');

        try {
            new OpenSearchHttpClient($transport)->sendRequest($request);
            self::fail('the request should not have completed');
        } catch (OpenSearchNetworkException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertInstanceOf(TransportException::class, $e->getPrevious());
        }
    }

    /**
     * The failure Symfony's lazy response would otherwise raise from the
     * PSR-7 body stream after sendRequest() had already returned.
     */
    public function test_a_body_phase_failure_arrives_as_a_network_exception_carrying_the_request(): void
    {
        $transport = new MockHttpClient(new MockResponse(
            (static function (): iterable {
                yield '{"hits":';
                yield new TransportException('the connection dropped mid-body');
            })(),
            ['response_headers' => ['content-type' => 'application/json']],
        ));
        $request = new Request('GET', 'https://localhost:9200/articles/_search');

        try {
            new OpenSearchHttpClient($transport)->sendRequest($request);
            self::fail('the request should not have completed');
        } catch (OpenSearchNetworkException $e) {
            self::assertSame($request, $e->getRequest());
        }
    }

    public function test_a_response_past_the_configured_bound_arrives_as_a_network_exception(): void
    {
        $transport = new MockHttpClient(new MockResponse(
            (static function (): iterable {
                yield str_repeat('a', 48);
                yield str_repeat('b', 48);
            })(),
        ))->withOptions(['on_progress' => $this->responseBoundFor('64')]);
        $request = new Request('GET', 'https://localhost:9200/articles/_search');

        try {
            new OpenSearchHttpClient($transport)->sendRequest($request);
            self::fail('the request should not have completed');
        } catch (OpenSearchNetworkException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertStringContainsString(
                'SEARCH_OPENSEARCH_MAX_RESPONSE_BYTES',
                $e->getPrevious()?->getMessage() ?? '',
            );
        }
    }

    public function test_a_response_within_the_configured_bound_is_returned_whole(): void
    {
        $body = str_repeat('a', 64);
        $transport = new MockHttpClient(new MockResponse($body))
            ->withOptions(['on_progress' => $this->responseBoundFor('64')]);

        $response = new OpenSearchHttpClient($transport)->sendRequest(
            new Request('GET', 'https://localhost:9200/articles/_search'),
        );

        self::assertSame($body, (string) $response->getBody());
    }

    private function openSearchClientOver(HttpClientInterface $transport): Client
    {
        return new Client(
            new TransportFactory()->setHttpClient(new OpenSearchHttpClient($transport))->create(),
            new EndpointFactory(),
        );
    }

    /**
     * The guard the factory installs for a given limit, so this asserts
     * the configured one rather than a copy of it.
     */
    private function responseBoundFor(string $limit): Closure
    {
        $client = OpenSearchClientFactory::fromConfig(new Config([
            'SEARCH_OPENSEARCH_HOST' => 'https://localhost:9200',
            'SEARCH_OPENSEARCH_MAX_RESPONSE_BYTES' => $limit,
        ]));

        $httpTransport = new ReflectionProperty($client, 'httpTransport')->getValue($client);
        $adapter = new ReflectionProperty($httpTransport, 'client')->getValue($httpTransport);
        $ampClient = new ReflectionProperty($adapter, 'client')->getValue($adapter);

        /** @var array<string, mixed> $options */
        $options = new ReflectionProperty($ampClient, 'defaultOptions')->getValue($ampClient);

        /** @var Closure $guard */
        $guard = $options['on_progress'];

        return $guard;
    }
}
