<?php

declare(strict_types=1);

namespace Kinetis\Search\Tests;

use Kinetis\Config\Config;
use Kinetis\Search\BufferedHttpClient;
use Kinetis\Search\Exception\SearchNetworkException;
use Kinetis\Search\Exception\SearchResponseTooLargeException;
use Kinetis\Search\SearchTransport;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BufferedHttpClientTest extends TestCase
{
    public function test_a_successful_response_is_complete_before_send_request_returns(): void
    {
        $transport = new MockHttpClient(new MockResponse(
            '{"took":3,"hits":{"total":{"value":1}}}',
            ['response_headers' => ['content-type' => 'application/json', 'x-elastic-product' => 'Elasticsearch']],
        ));

        $response = new BufferedHttpClient($transport)->sendRequest(
            new Request('GET', 'https://localhost:9200/articles/_search'),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"took":3,"hits":{"total":{"value":1}}}', $response->getBody()->getContents());
        self::assertSame('application/json', $response->getHeaderLine('content-type'));
        self::assertSame('Elasticsearch', $response->getHeaderLine('x-elastic-product'));
    }

    /**
     * A 4xx is a response, not a transport failure: it reaches the caller
     * whole so the engine client can map it.
     */
    public function test_a_4xx_response_is_buffered_and_passed_through(): void
    {
        $transport = new MockHttpClient(new MockResponse(
            '{"error":{"type":"index_not_found_exception"},"status":404}',
            ['http_code' => 404, 'response_headers' => ['content-type' => 'application/json']],
        ));

        $response = new BufferedHttpClient($transport)->sendRequest(
            new Request('GET', 'https://localhost:9200/missing/_doc/1'),
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('{"error":{"type":"index_not_found_exception"},"status":404}', (string) $response->getBody());
        self::assertSame('application/json', $response->getHeaderLine('content-type'));
    }

    public function test_the_request_reaches_the_transport_unchanged(): void
    {
        $seen = [];
        $transport = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('{}', ['response_headers' => ['content-type' => 'application/json']]);
        });

        new BufferedHttpClient($transport)->sendRequest(
            new Request(
                'POST',
                'https://localhost:9200/articles/_search',
                ['Content-Type' => 'application/x-ndjson'],
                '{"query":{"match_all":{}}}',
            ),
        );

        self::assertSame('POST', $seen['method']);
        self::assertSame('https://localhost:9200/articles/_search', $seen['url']);
        self::assertSame('{"query":{"match_all":{}}}', $seen['options']['body']);
        self::assertContains('Content-Type: application/x-ndjson', $seen['options']['headers']);
    }

    /**
     * The response bound counts bytes off the wire, so a compressed body
     * would let a far larger decoded one through it. Elasticsearch's own
     * ClientBuilder asks for gzip on an Elastic Cloud host.
     */
    public function test_a_requested_content_coding_is_replaced_with_identity(): void
    {
        $seen = [];
        $transport = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = $options['headers'];

            return new MockResponse('{}');
        });

        new BufferedHttpClient($transport)->sendRequest(
            new Request('GET', 'https://localhost:9200/_search', ['accept-ENCODING' => 'gzip']),
        );

        self::assertContains('Accept-Encoding: identity', $seen);
        self::assertNotContains('accept-ENCODING: gzip', $seen);
    }

    public function test_identity_is_asked_for_even_when_a_request_names_no_coding(): void
    {
        $seen = [];
        $transport = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = $options['headers'];

            return new MockResponse('{}');
        });

        new BufferedHttpClient($transport)->sendRequest(new Request('GET', 'https://localhost:9200/_search'));

        self::assertContains('Accept-Encoding: identity', $seen);
    }

    public function test_a_connection_failure_arrives_as_a_network_exception_carrying_the_request(): void
    {
        $transport = new MockHttpClient(new MockResponse('', ['error' => 'connection refused']));
        $request = new Request('GET', 'https://localhost:9200/articles/_search');

        try {
            new BufferedHttpClient($transport)->sendRequest($request);
            self::fail('the request should not have completed');
        } catch (SearchNetworkException $e) {
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
            new BufferedHttpClient($transport)->sendRequest($request);
            self::fail('the request should not have completed');
        } catch (SearchNetworkException $e) {
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
            new BufferedHttpClient($transport)->sendRequest($request);
            self::fail('the request should not have completed');
        } catch (SearchNetworkException $e) {
            self::assertSame($request, $e->getRequest());
            // The abort itself, two levels down: the guard's own
            // exception, under the transport exception Symfony reports
            // an aborted transfer as.
            $abort = $e->getPrevious()?->getPrevious();
            self::assertInstanceOf(SearchResponseTooLargeException::class, $abort);
            self::assertStringContainsString('SEARCH_ENGINE_MAX_RESPONSE_BYTES', $abort->getMessage());
        }
    }

    public function test_a_response_within_the_configured_bound_is_returned_whole(): void
    {
        $body = str_repeat('a', 64);
        $transport = new MockHttpClient(new MockResponse($body))
            ->withOptions(['on_progress' => $this->responseBoundFor('64')]);

        $response = new BufferedHttpClient($transport)->sendRequest(
            new Request('GET', 'https://localhost:9200/articles/_search'),
        );

        self::assertSame($body, (string) $response->getBody());
    }

    /**
     * The guard {@see SearchTransport} installs for a given limit, so
     * this asserts the configured one rather than a copy of it.
     */
    private function responseBoundFor(string $limit): \Closure
    {
        $transport = SearchTransport::fromConfig(
            new Config([
                'SEARCH_ENGINE_HOST' => 'https://localhost:9200',
                'SEARCH_ENGINE_MAX_RESPONSE_BYTES' => $limit,
            ]),
            'SEARCH_ENGINE',
        );

        $adapter = $transport->client;
        $ampClient = new \ReflectionProperty($adapter, 'client')->getValue($adapter);

        /** @var array<string, mixed> $options */
        $options = new \ReflectionProperty($ampClient, 'defaultOptions')->getValue($ampClient);

        /** @var \Closure $guard */
        $guard = $options['on_progress'];

        return $guard;
    }
}
