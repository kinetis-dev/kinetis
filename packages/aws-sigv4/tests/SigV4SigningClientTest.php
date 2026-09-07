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
 * What reaches the transport for a request that is already on the
 * configured origin: the headers the signer owns, the headers it leaves
 * alone, and the body. {@see SignatureTest} covers the algorithm those
 * headers carry.
 */
final class SigV4SigningClientTest extends TestCase
{
    private const string ORIGIN = 'https://example.amazonaws.com';

    public function test_returns_the_transport_response(): void
    {
        $transport = new RecordingTransport([['status' => 201, 'body' => 'created']]);
        $client = $this->client($transport);

        $response = $client->sendRequest(new Request('GET', 'https://example.amazonaws.com/'));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('created', (string) $response->getBody());
    }

    /**
     * `Host` names the trusted origin whatever the caller's own header
     * said, and the timestamp is the signer's, so the two headers the
     * signature is built on cannot be set from outside.
     */
    public function test_the_signer_owns_the_host_and_date_headers(): void
    {
        $transport = new RecordingTransport();

        $this->client($transport)->sendRequest(
            (new Request('GET', 'https://example.amazonaws.com/'))
                ->withHeader('Host', 'evil.example.com')
                ->withHeader('X-Amz-Date', '19700101T000000Z'),
        );

        self::assertSame('example.amazonaws.com', $transport->headerLineOfCall(0, 'Host'));
        self::assertNotSame('19700101T000000Z', $transport->headerLineOfCall(0, 'X-Amz-Date'));
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
     * Credentials without a token own the header too: a caller's own
     * `X-Amz-Security-Token` would otherwise be signed and sent as if it
     * came with the credentials the request is signed under.
     */
    public function test_a_caller_security_token_is_removed_when_the_credentials_carry_none(): void
    {
        $transport = new RecordingTransport();

        $this->client($transport)->sendRequest(
            (new Request('GET', 'https://example.amazonaws.com/'))
                ->withHeader('X-Amz-Security-Token', 'a-caller-token'),
        );

        self::assertSame('', $transport->headerLineOfCall(0, 'X-Amz-Security-Token'));
        self::assertStringContainsString(
            'SignedHeaders=host;x-amz-date,',
            $transport->headerLineOfCall(0, 'Authorization'),
        );
    }

    /**
     * A plain PSR-7 withHeader() call already puts X-Custom on the
     * outgoing request whatever this class does; what has to be checked
     * is whether the signature covers it, which the `SignedHeaders`
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
     * A repeated header is joined for the canonical request only. The
     * outgoing request keeps every value it was given, in order.
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
     * arrive byte-identical, proving the canonical join never leaks into
     * what is sent even where the joined string would, if re-split on
     * commas, look like more values than there are. The session token
     * alongside it confirms every signer-owned header still reaches the
     * transport at the same time.
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
     * request body, or a pipe. It is read where it stands, like every
     * other body, and both signing and sending still work.
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
     * Signing reads the caller's stream from where it stands through
     * EOF and consumes it: what is signed and sent is what was left to
     * read, and the caller's stream is at its end afterwards.
     */
    public function test_signing_consumes_the_request_body_from_its_current_position(): void
    {
        $transport = new RecordingTransport();
        $request = new Request('POST', 'https://example.amazonaws.com/', [], 'abcdef');
        $body = $request->getBody();
        $body->seek(2);

        $this->client($transport)->sendRequest($request);

        self::assertSame('cdef', $transport->bodyOfCall(0));
        self::assertTrue($body->eof());
    }

    /**
     * A body past `php://temp`'s 2MB in-memory threshold round-trips
     * through both signing and the transport.
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
