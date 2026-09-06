<?php

declare(strict_types=1);

namespace Kinetis\McpDocs\Tests;

use Kinetis\McpDocs\DocsFetcher;
use Kinetis\McpDocs\DocsPage;
use Kinetis\McpDocs\Exception\DocsFetchException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DocsFetcherTest extends TestCase
{
    private const string SLUG = 'core-concepts';

    public function test_a_200_response_is_returned_as_the_pages_text(): void
    {
        $client = new MockHttpClient(new MockResponse("# Core Concepts\n"));

        self::assertSame("# Core Concepts\n", new DocsFetcher($client)->fetch(self::page()));
    }

    public function test_a_body_arriving_in_several_chunks_is_reassembled(): void
    {
        $client = new MockHttpClient(new MockResponse(['# Core', ' Concepts', "\n"]));

        self::assertSame("# Core Concepts\n", new DocsFetcher($client)->fetch(self::page()));
    }

    public function test_the_request_goes_to_the_pages_own_https_url(): void
    {
        $response = new MockResponse('body');
        new DocsFetcher(new MockHttpClient($response))->fetch(self::page());

        self::assertSame(
            'https://raw.githubusercontent.com/kinetis-dev/kinetis/main/docs/core-concepts.md',
            $response->getRequestUrl(),
        );
        self::assertSame('GET', $response->getRequestMethod());
    }

    public function test_the_request_follows_no_redirect_and_carries_both_deadlines(): void
    {
        $response = new MockResponse('body');
        new DocsFetcher(new MockHttpClient($response))->fetch(self::page());

        $options = $response->getRequestOptions();

        self::assertSame(0, $options['max_redirects']);
        self::assertSame(10.0, $options['timeout']);
        self::assertSame(30.0, $options['max_duration']);
    }

    public static function nonOkStatusProvider(): iterable
    {
        yield 'a redirect that is never followed' => [302];
        yield 'not found' => [404];
        yield 'a server failure' => [500];
    }

    #[DataProvider('nonOkStatusProvider')]
    public function test_a_status_other_than_200_fails_naming_the_status(int $status): void
    {
        $client = new MockHttpClient(new MockResponse('body', ['http_code' => $status]));

        $this->expectException(DocsFetchException::class);
        $this->expectExceptionMessage("got {$status}");

        new DocsFetcher($client)->fetch(self::page());
    }

    public function test_a_transport_failure_is_reported_with_its_own_reason(): void
    {
        $client = new MockHttpClient(static fn (): never => throw new TransportException('name resolution failed'));

        $this->expectException(DocsFetchException::class);
        $this->expectExceptionMessage('name resolution failed');

        new DocsFetcher($client)->fetch(self::page());
    }

    public function test_a_body_past_the_size_cap_is_abandoned(): void
    {
        $client = new MockHttpClient(new MockResponse([
            str_repeat('a', 3 * 1024 * 1024),
            str_repeat('b', 2 * 1024 * 1024),
        ]));

        $this->expectException(DocsFetchException::class);
        $this->expectExceptionMessage('exceeds the 4194304-byte cap');

        new DocsFetcher($client)->fetch(self::page());
    }

    public function test_a_body_at_the_size_cap_is_returned(): void
    {
        $body = str_repeat('a', 4 * 1024 * 1024);
        $client = new MockHttpClient(new MockResponse($body));

        self::assertSame($body, new DocsFetcher($client)->fetch(self::page()));
    }

    public function test_the_default_client_is_built_when_none_is_given(): void
    {
        // Constructing it is the whole assertion: no request is made, so
        // nothing here reaches the network.
        $fetcher = new DocsFetcher();

        self::assertInstanceOf(DocsFetcher::class, $fetcher);
    }

    private static function page(): DocsPage
    {
        return new DocsPage(self::SLUG, 'Core Concepts', 'The runtime-agnostic Kernel');
    }
}
