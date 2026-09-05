<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4\Tests;

use Kinetis\AwsSigV4\SigV4SigningClient;
use Kinetis\AwsSigV4\Tests\Support\FixedCredentialProvider;
use Kinetis\AwsSigV4\Tests\Support\NonSeekableStream;
use Kinetis\AwsSigV4\Tests\Support\RecordingTransport;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\TestCase;

/**
 * Signing behavior: what reaches the transport for a request that is
 * already on the configured origin.
 */
final class SigV4SigningClientTest extends TestCase
{
    private const string ORIGIN = 'https://example.amazonaws.com';

    /**
     * AWS's published "get-vanilla" SigV4 test vector: a fixed date
     * (2015-08-30T12:36:00Z), the static AKIDEXAMPLE credentials, region
     * "us-east-1", the placeholder service name "service", and a plain
     * GET with no extra headers or query string. The expected
     * `Authorization` header is AWS's own ground truth, byte for byte.
     */
    public function test_matches_the_aws_published_get_vanilla_test_vector(): void
    {
        $transport = new RecordingTransport();
        $client = new SigV4SigningClient(
            self::ORIGIN,
            'us-east-1',
            'service',
            FixedCredentialProvider::example(),
            new \DateTimeImmutable('2015-08-30T12:36:00Z'),
            $transport->asTransport(),
        );

        $client->sendRequest(new Request('GET', 'https://example.amazonaws.com/'));

        self::assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, '
            . 'SignedHeaders=host;x-amz-date, '
            . 'Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
            $transport->headerLineOfCall(0, 'Authorization'),
        );
        self::assertSame('example.amazonaws.com', $transport->headerLineOfCall(0, 'Host'));
        self::assertSame('20150830T123600Z', $transport->headerLineOfCall(0, 'X-Amz-Date'));
    }

    public function test_returns_the_transport_response(): void
    {
        $transport = new RecordingTransport([['status' => 201, 'body' => 'created']]);
        $client = $this->client($transport);

        $response = $client->sendRequest(new Request('GET', 'https://example.amazonaws.com/'));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('created', (string) $response->getBody());
    }

    public function test_a_session_token_is_signed_in_as_a_header(): void
    {
        $transport = new RecordingTransport();
        $client = new SigV4SigningClient(
            self::ORIGIN,
            'us-east-1',
            'service',
            FixedCredentialProvider::withSessionToken('a-session-token'),
            null,
            $transport->asTransport(),
        );

        $client->sendRequest(new Request('GET', 'https://example.amazonaws.com/'));

        self::assertSame('a-session-token', $transport->headerLineOfCall(0, 'X-Amz-Security-Token'));
    }

    /**
     * A plain PSR-7 withHeader() call already puts X-Custom on the
     * outgoing request whatever this class does; what has to be checked
     * is whether SignerV4 signed over it, which the `SignedHeaders`
     * portion of the Authorization header is the evidence for.
     */
    public function test_an_existing_header_is_included_in_the_signature(): void
    {
        $transport = new RecordingTransport();

        $this->client($transport)->sendRequest(
            (new Request('GET', 'https://example.amazonaws.com/'))->withHeader('X-Custom', 'my-value'),
        );

        self::assertStringContainsString(
            'SignedHeaders=host;x-amz-date;x-custom,',
            $transport->headerLineOfCall(0, 'Authorization'),
        );
    }

    /**
     * A repeated header is folded into one comma-joined string as
     * SignerV4's canonical signing input only. Writing that folded
     * string back onto the outgoing request would merge the caller's two
     * distinct values into one.
     */
    public function test_a_repeated_header_survives_signing_with_its_values_and_order_intact(): void
    {
        $transport = new RecordingTransport();

        $this->client($transport)->sendRequest(
            (new Request('GET', 'https://example.amazonaws.com/'))
                ->withHeader('X-Custom', 'first')
                ->withAddedHeader('X-Custom', 'second'),
        );

        self::assertSame(['first', 'second'], $transport->headersOfCall(0)['x-custom'] ?? []);
        self::assertStringContainsString(
            'SignedHeaders=host;x-amz-date;x-custom,',
            $transport->headerLineOfCall(0, 'Authorization'),
        );
    }

    /**
     * A header value carrying its own comma (a Cookie-shaped one) must
     * arrive byte-identical, proving the fold-for-signing step never
     * leaks into what is sent even where the folded string would, if
     * re-split on commas, look like more values than there are. The
     * session token alongside it confirms every signer-owned header
     * still reaches the transport at the same time.
     */
    public function test_a_header_with_an_embedded_comma_survives_signing_byte_identical(): void
    {
        $transport = new RecordingTransport();
        $client = new SigV4SigningClient(
            self::ORIGIN,
            'us-east-1',
            'service',
            FixedCredentialProvider::withSessionToken('a-session-token'),
            null,
            $transport->asTransport(),
        );

        $client->sendRequest(
            (new Request('GET', 'https://example.amazonaws.com/'))
                ->withHeader('Cookie', 'session=abc, extra=value')
                ->withAddedHeader('Cookie', 'other=xyz'),
        );

        self::assertSame(
            ['session=abc, extra=value', 'other=xyz'],
            $transport->headersOfCall(0)['cookie'] ?? [],
        );
        self::assertStringContainsString(
            'SignedHeaders=cookie;host;x-amz-date;x-amz-security-token,',
            $transport->headerLineOfCall(0, 'Authorization'),
        );
        self::assertSame('a-session-token', $transport->headerLineOfCall(0, 'X-Amz-Security-Token'));
    }

    public function test_the_request_body_reaches_the_transport_after_signing(): void
    {
        $transport = new RecordingTransport();

        $this->client($transport)->sendRequest(
            new Request('POST', 'https://example.amazonaws.com/', [], '{"query":{"match_all":{}}}'),
        );

        self::assertSame('{"query":{"match_all":{}}}', $transport->bodyOfCall(0));
    }

    /**
     * PSR-7 permits a stream that cannot be seeked at all — a chunked
     * request body, or a pipe. Such a body is read from where it is
     * rather than rewound, and both signing and sending still work.
     */
    public function test_a_non_seekable_request_body_is_signed_and_sent(): void
    {
        $transport = new RecordingTransport();

        $response = $this->client($transport)->sendRequest(
            (new Request('POST', 'https://example.amazonaws.com/'))
                ->withBody(new NonSeekableStream('{"query":{"match_all":{}}}')),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Signature=', $transport->headerLineOfCall(0, 'Authorization'));
        self::assertSame('{"query":{"match_all":{}}}', $transport->bodyOfCall(0));
    }

    /**
     * `SpooledStream` is backed by `php://temp`, which stays in memory
     * up to 2MB before spilling to a real temp file; a body past that
     * boundary round-trips through both signing and the transport.
     */
    public function test_a_large_request_body_past_the_in_memory_threshold_is_signed_and_sent(): void
    {
        $transport = new RecordingTransport();
        $largeBody = str_repeat('a', 3 * 1024 * 1024);

        $this->client($transport)->sendRequest(
            new Request('POST', 'https://example.amazonaws.com/', [], $largeBody),
        );

        self::assertStringContainsString('Signature=', $transport->headerLineOfCall(0, 'Authorization'));
        self::assertSame($largeBody, $transport->bodyOfCall(0));
    }

    /**
     * The stream a caller built their request with is the one this class
     * reads to compute the signature, so rewinding and reading it is a
     * visible mutation of their own object unless the original position
     * is restored: a 6-byte body seeked to offset 2 before sendRequest()
     * still reads offset 2 afterward.
     */
    public function test_a_seekable_request_bodys_original_cursor_position_is_restored(): void
    {
        $transport = new RecordingTransport();
        $request = new Request('POST', 'https://example.amazonaws.com/', [], 'abcdef');
        $body = $request->getBody();
        $body->seek(2);

        $this->client($transport)->sendRequest($request);

        self::assertSame(2, $body->tell());
        self::assertSame('abcdef', $transport->bodyOfCall(0));
    }

    /**
     * The redirect ceiling reaches the transport on the request itself,
     * not only in its default options; see {@see RedirectTest} for what
     * that ceiling is worth once a 3xx comes back.
     */
    public function test_every_signed_request_reaches_the_transport_with_redirects_disabled(): void
    {
        $transport = new RecordingTransport();

        $this->client($transport)->sendRequest(new Request('GET', 'https://example.amazonaws.com/'));

        self::assertSame(0, $transport->optionOfCall(0, 'max_redirects'));
    }

    private function client(RecordingTransport $transport): SigV4SigningClient
    {
        return new SigV4SigningClient(
            self::ORIGIN,
            'us-east-1',
            'service',
            FixedCredentialProvider::example(),
            null,
            $transport->asTransport(),
        );
    }
}
