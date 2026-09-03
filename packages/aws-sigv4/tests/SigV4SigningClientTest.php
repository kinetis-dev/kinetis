<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4\Tests;

use AsyncAws\Core\Configuration;
use AsyncAws\Core\Credentials\CredentialProvider;
use AsyncAws\Core\Credentials\Credentials;
use Kinetis\AwsSigV4\Exception\SigningException;
use Kinetis\AwsSigV4\Exception\UnsignableRequestException;
use Kinetis\AwsSigV4\SigV4SigningClient;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

final class SigV4SigningClientTest extends TestCase
{
    /**
     * AWS's own published "get-vanilla" SigV4 test vector: a fixed date
     * (2015-08-30T12:36:00Z), fixed static test credentials
     * (AKIDEXAMPLE), region "us-east-1", the generic placeholder service
     * name "service", and a plain GET request with no extra headers or
     * query string. Proves this class wires a real PSR-7 request into
     * AsyncAws's own Request/Credentials/RequestContext correctly and
     * produces the exact byte-correct Authorization header AWS itself
     * publishes as ground truth — not just "a signature that looks
     * plausible".
     */
    public function test_matches_the_aws_published_get_vanilla_test_vector(): void
    {
        $recordingClient = new RecordingClient();
        $credentialProvider = new FixedCredentialProvider(new Credentials(
            'AKIDEXAMPLE',
            'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
        ));
        $now = new \DateTimeImmutable('2015-08-30T12:36:00Z');

        $client = new SigV4SigningClient($recordingClient, 'us-east-1', 'service', $credentialProvider, $now);

        $client->sendRequest(new Request('GET', 'https://example.amazonaws.com/'));

        self::assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, SignedHeaders=host;x-amz-date, Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
            $recordingClient->captured?->getHeaderLine('Authorization'),
        );
        self::assertSame('example.amazonaws.com', $recordingClient->captured?->getHeaderLine('Host'));
        self::assertSame('20150830T123600Z', $recordingClient->captured?->getHeaderLine('X-Amz-Date'));
    }

    public function test_delegates_to_the_wrapped_client_and_returns_its_response(): void
    {
        $recordingClient = new RecordingClient();
        $credentialProvider = new FixedCredentialProvider(new Credentials('AKIDEXAMPLE', 'secret'));

        $client = new SigV4SigningClient($recordingClient, 'us-east-1', 'service', $credentialProvider);

        $response = $client->sendRequest(new Request('GET', 'https://example.amazonaws.com/'));

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_a_session_token_is_signed_in_as_a_header(): void
    {
        $recordingClient = new RecordingClient();
        $credentialProvider = new FixedCredentialProvider(new Credentials(
            'AKIDEXAMPLE',
            'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
            'a-session-token',
        ));

        $client = new SigV4SigningClient($recordingClient, 'us-east-1', 'service', $credentialProvider);
        $client->sendRequest(new Request('GET', 'https://example.amazonaws.com/'));

        self::assertSame('a-session-token', $recordingClient->captured?->getHeaderLine('X-Amz-Security-Token'));
    }

    public function test_an_existing_header_on_the_original_request_is_included_in_the_signature(): void
    {
        // A plain PSR-7 withHeader() call already guarantees X-Custom
        // survives onto the signed request regardless of anything this
        // class does — copying headers into the AwsRequest only matters
        // for whether SignerV4 actually includes it in what gets signed,
        // so that's what this test has to check, via the Authorization
        // header's own SignedHeaders portion, not just presence.
        $recordingClient = new RecordingClient();
        $credentialProvider = new FixedCredentialProvider(new Credentials('AKIDEXAMPLE', 'secret'));

        $client = new SigV4SigningClient($recordingClient, 'us-east-1', 'service', $credentialProvider);
        $client->sendRequest(
            (new Request('GET', 'https://example.amazonaws.com/'))->withHeader('X-Custom', 'my-value'),
        );

        self::assertStringContainsString(
            'SignedHeaders=host;x-amz-date;x-custom,',
            $recordingClient->captured?->getHeaderLine('Authorization') ?? '',
        );
    }

    /**
     * The regression this class's own header-copy-back logic must not
     * reintroduce: a repeated header (two X-Custom values) is folded
     * into one comma-joined string purely as SignerV4's own canonical
     * signing input — that folded string must never be written back onto
     * the outgoing request, which would silently and permanently merge
     * the caller's own two distinct values into one.
     */
    public function test_a_repeated_header_survives_signing_with_its_values_and_order_intact(): void
    {
        $recordingClient = new RecordingClient();
        $credentialProvider = new FixedCredentialProvider(new Credentials('AKIDEXAMPLE', 'secret'));

        $client = new SigV4SigningClient($recordingClient, 'us-east-1', 'service', $credentialProvider);
        $client->sendRequest(
            (new Request('GET', 'https://example.amazonaws.com/'))
                ->withHeader('X-Custom', 'first')
                ->withAddedHeader('X-Custom', 'second'),
        );

        self::assertSame(['first', 'second'], $recordingClient->captured?->getHeader('X-Custom'));
        self::assertStringContainsString(
            'SignedHeaders=host;x-amz-date;x-custom,',
            $recordingClient->captured?->getHeaderLine('Authorization') ?? '',
        );
        self::assertNotSame('', $recordingClient->captured?->getHeaderLine('Host'));
        self::assertNotSame('', $recordingClient->captured?->getHeaderLine('X-Amz-Date'));
    }

    /**
     * A header value that itself contains a comma (a realistic
     * Cookie-header shape) must survive signing exactly as supplied —
     * not just present, but byte-identical — proving the
     * fold-for-signing step never leaks into what's actually sent, even
     * when the folded string would (if it were naively re-split on
     * commas) look like more values than there really are. Also covers a
     * session token alongside it, confirming every genuinely
     * signer-owned header (Authorization, Host,
     * X-Amz-Date, X-Amz-Security-Token) still reaches the delegated
     * request at the same time an unrelated multi-value header is left
     * alone.
     */
    public function test_a_header_with_an_embedded_comma_survives_signing_byte_identical(): void
    {
        $recordingClient = new RecordingClient();
        $credentialProvider = new FixedCredentialProvider(new Credentials(
            'AKIDEXAMPLE',
            'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
            'a-session-token',
        ));

        $client = new SigV4SigningClient($recordingClient, 'us-east-1', 'service', $credentialProvider);
        $client->sendRequest(
            (new Request('GET', 'https://example.amazonaws.com/'))
                ->withHeader('Cookie', 'session=abc, extra=value')
                ->withAddedHeader('Cookie', 'other=xyz'),
        );

        self::assertSame(
            ['session=abc, extra=value', 'other=xyz'],
            $recordingClient->captured?->getHeader('Cookie'),
        );
        self::assertStringContainsString(
            'SignedHeaders=cookie;host;x-amz-date;x-amz-security-token,',
            $recordingClient->captured?->getHeaderLine('Authorization') ?? '',
        );
        self::assertNotSame('', $recordingClient->captured?->getHeaderLine('Host'));
        self::assertNotSame('', $recordingClient->captured?->getHeaderLine('X-Amz-Date'));
        self::assertSame('a-session-token', $recordingClient->captured?->getHeaderLine('X-Amz-Security-Token'));
    }

    public function test_the_request_body_is_still_readable_by_the_wrapped_client_after_signing(): void
    {
        $recordingClient = new RecordingClient();
        $credentialProvider = new FixedCredentialProvider(new Credentials('AKIDEXAMPLE', 'secret'));

        $client = new SigV4SigningClient($recordingClient, 'us-east-1', 'service', $credentialProvider);
        $client->sendRequest(
            new Request('POST', 'https://example.amazonaws.com/', [], '{"query":{"match_all":{}}}'),
        );

        // getContents() reads from the stream's *current* position, unlike
        // (string) casting via __toString() — which auto-rewinds first
        // regardless, and so would still pass even if this class's own
        // explicit rewind() were removed. A wrapped HTTP client reading the
        // body via a raw read/getContents() call (not __toString()) is
        // exactly the real scenario that rewind() exists to protect.
        self::assertSame('{"query":{"match_all":{}}}', $recordingClient->captured?->getBody()->getContents());
    }

    public function test_a_relative_request_uri_is_resolved_against_base_uri_before_signing(): void
    {
        $recordingClient = new RecordingClient();
        $credentialProvider = new FixedCredentialProvider(new Credentials('AKIDEXAMPLE', 'secret'));

        $client = new SigV4SigningClient(
            $recordingClient,
            'us-east-1',
            'es',
            $credentialProvider,
            baseUri: 'https://search-my-domain.us-east-1.es.amazonaws.com',
        );
        $client->sendRequest(new Request('GET', '/_cluster/health'));

        self::assertSame(
            'search-my-domain.us-east-1.es.amazonaws.com',
            $recordingClient->captured?->getUri()->getHost(),
        );
        self::assertSame('https', $recordingClient->captured?->getUri()->getScheme());
        self::assertSame('/_cluster/health', $recordingClient->captured?->getUri()->getPath());
        self::assertSame(
            'search-my-domain.us-east-1.es.amazonaws.com',
            $recordingClient->captured?->getHeaderLine('Host'),
        );
    }

    public function test_base_uri_with_a_path_prefix_is_prepended_onto_the_request_path(): void
    {
        $recordingClient = new RecordingClient();
        $credentialProvider = new FixedCredentialProvider(new Credentials('AKIDEXAMPLE', 'secret'));

        $client = new SigV4SigningClient(
            $recordingClient,
            'us-east-1',
            'execute-api',
            $credentialProvider,
            baseUri: 'https://api.example.com/prod',
        );
        $client->sendRequest(new Request('GET', '/users'));

        self::assertSame('/prod/users', $recordingClient->captured?->getUri()->getPath());
    }

    public function test_a_request_that_already_has_a_host_is_left_untouched_even_with_base_uri_set(): void
    {
        $recordingClient = new RecordingClient();
        $credentialProvider = new FixedCredentialProvider(new Credentials('AKIDEXAMPLE', 'secret'));

        $client = new SigV4SigningClient(
            $recordingClient,
            'us-east-1',
            'service',
            $credentialProvider,
            baseUri: 'https://should-not-be-used.example.com',
        );
        $client->sendRequest(new Request('GET', 'https://example.amazonaws.com/'));

        self::assertSame('example.amazonaws.com', $recordingClient->captured?->getUri()->getHost());
    }

    /**
     * A malformed baseUri is a boot-time failure, not one that waits for
     * the first relative-URI request to be sent — the exception must
     * come from constructing the client, not from sendRequest().
     */
    public function test_an_invalid_base_uri_throws_a_clear_error_at_construction(): void
    {
        $recordingClient = new RecordingClient();
        $credentialProvider = new FixedCredentialProvider(new Credentials('AKIDEXAMPLE', 'secret'));

        $this->expectException(SigningException::class);
        $this->expectExceptionMessage(
            'baseUri is not a valid absolute URI (must include a scheme and host).',
        );

        new SigV4SigningClient(
            $recordingClient,
            'us-east-1',
            'service',
            $credentialProvider,
            baseUri: 'not-a-valid-uri',
        );
    }

    public function test_an_unsupported_base_uri_scheme_throws_a_clear_error_at_construction(): void
    {
        $recordingClient = new RecordingClient();
        $credentialProvider = new FixedCredentialProvider(new Credentials('AKIDEXAMPLE', 'secret'));

        $this->expectException(SigningException::class);
        $this->expectExceptionMessage(
            'baseUri uses unsupported scheme "ftp" — only "http" and "https" are supported.',
        );

        new SigV4SigningClient(
            $recordingClient,
            'us-east-1',
            'service',
            $credentialProvider,
            baseUri: 'ftp://api.example.com',
        );
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function unsupportedBaseUriComponentsProvider(): iterable
    {
        yield 'userinfo' => ['https://user:pass@api.example.com'];
        yield 'query string' => ['https://api.example.com?x=1'];
        yield 'fragment' => ['https://api.example.com#section'];
    }

    /**
     * parse_url() silently drops userinfo/query/fragment from the parts
     * this class actually reads — nothing about that result indicates
     * anything was discarded, so each has to be checked and rejected
     * explicitly rather than silently ignored.
     */
    #[DataProvider('unsupportedBaseUriComponentsProvider')]
    public function test_base_uri_with_unsupported_components_throws_a_clear_error_at_construction(string $baseUri): void
    {
        $recordingClient = new RecordingClient();
        $credentialProvider = new FixedCredentialProvider(new Credentials('AKIDEXAMPLE', 'secret'));

        $this->expectException(SigningException::class);
        $this->expectExceptionMessage(
            'baseUri must not include userinfo, a query string, or a fragment '
            . '— only scheme, host, port, and path are used.',
        );

        new SigV4SigningClient(
            $recordingClient,
            'us-east-1',
            'service',
            $credentialProvider,
            baseUri: $baseUri,
        );
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function secretBearingBaseUriProvider(): iterable
    {
        yield 'userinfo password' => ['https://deploy:super-secret-password@api.example.com'];
        yield 'query string token' => ['https://api.example.com?token=super-secret-token'];
        yield 'malformed with embedded userinfo' => ['ht!tp://deploy:super-secret-password@api.example.com'];
    }

    /**
     * A rejected baseUri may itself carry a real credential (a password
     * in userinfo, a token in the query string) — that value must never
     * reach the exception message, since a caller catching and logging
     * SigningException would otherwise copy the secret verbatim into a
     * log. Covers all three rejection paths a secret-bearing baseUri can
     * actually take: fails to parse at all, an unsupported scheme, and
     * the userinfo/query/fragment check.
     */
    #[DataProvider('secretBearingBaseUriProvider')]
    public function test_a_secret_bearing_base_uri_never_appears_in_the_exception_message(string $baseUri): void
    {
        $recordingClient = new RecordingClient();
        $credentialProvider = new FixedCredentialProvider(new Credentials('AKIDEXAMPLE', 'secret'));

        try {
            new SigV4SigningClient(
                $recordingClient,
                'us-east-1',
                'service',
                $credentialProvider,
                baseUri: $baseUri,
            );

            self::fail('Expected a SigningException to be thrown.');
        } catch (SigningException $e) {
            self::assertStringNotContainsString('super-secret-password', $e->getMessage());
            self::assertStringNotContainsString('super-secret-token', $e->getMessage());
            self::assertStringNotContainsString($baseUri, $e->getMessage());
        }
    }

    public function test_a_plain_http_base_uri_is_accepted_for_aws_compatible_local_endpoints(): void
    {
        $recordingClient = new RecordingClient();
        $credentialProvider = new FixedCredentialProvider(new Credentials('AKIDEXAMPLE', 'secret'));

        $client = new SigV4SigningClient(
            $recordingClient,
            'us-east-1',
            'service',
            $credentialProvider,
            baseUri: 'http://localhost:4566',
        );
        $client->sendRequest(new Request('GET', '/health'));

        self::assertSame('http', $recordingClient->captured?->getUri()->getScheme());
        self::assertSame('localhost', $recordingClient->captured?->getUri()->getHost());
        self::assertSame(4566, $recordingClient->captured?->getUri()->getPort());
    }

    /**
     * @return iterable<string, array{baseUri: string, expectedScheme: string}>
     */
    public static function mixedCaseSchemeProvider(): iterable
    {
        yield 'fully upper case https' => ['baseUri' => 'HTTPS://api.example.com', 'expectedScheme' => 'https'];
        yield 'mixed case https' => ['baseUri' => 'HttpS://api.example.com', 'expectedScheme' => 'https'];
        yield 'upper case http' => ['baseUri' => 'HTTP://api.example.com', 'expectedScheme' => 'http'];
    }

    /**
     * RFC 3986 §3.1: the scheme component is case-insensitive, so
     * "HTTPS://…" is exactly as valid an endpoint as "https://…" — this
     * must not be rejected as an "unsupported scheme".
     */
    #[DataProvider('mixedCaseSchemeProvider')]
    public function test_a_mixed_case_scheme_is_accepted_and_normalized(string $baseUri, string $expectedScheme): void
    {
        $recordingClient = new RecordingClient();
        $credentialProvider = new FixedCredentialProvider(new Credentials('AKIDEXAMPLE', 'secret'));

        $client = new SigV4SigningClient(
            $recordingClient,
            'us-east-1',
            'service',
            $credentialProvider,
            baseUri: $baseUri,
        );
        $client->sendRequest(new Request('GET', '/health'));

        self::assertSame($expectedScheme, $recordingClient->captured?->getUri()->getScheme());
    }

    /**
     * @return iterable<string, array{basePath: string, requestPath: string, expected: string}>
     */
    public static function pathJoiningProvider(): iterable
    {
        yield 'base without trailing slash, request with leading slash' => [
            'basePath' => '/prod', 'requestPath' => '/users', 'expected' => '/prod/users',
        ];
        yield 'base with trailing slash, request with leading slash' => [
            'basePath' => '/prod/', 'requestPath' => '/users', 'expected' => '/prod/users',
        ];
        // The exact bug this issue reports: PSR-7 permits a relative-
        // reference request path with no leading slash at all.
        yield 'base without trailing slash, request without leading slash' => [
            'basePath' => '/prod', 'requestPath' => 'users', 'expected' => '/prod/users',
        ];
        yield 'base with trailing slash, request without leading slash' => [
            'basePath' => '/prod/', 'requestPath' => 'users', 'expected' => '/prod/users',
        ];
    }

    #[DataProvider('pathJoiningProvider')]
    public function test_base_path_and_request_path_are_joined_with_exactly_one_slash(
        string $basePath,
        string $requestPath,
        string $expected,
    ): void {
        $recordingClient = new RecordingClient();
        $credentialProvider = new FixedCredentialProvider(new Credentials('AKIDEXAMPLE', 'secret'));

        $client = new SigV4SigningClient(
            $recordingClient,
            'us-east-1',
            'execute-api',
            $credentialProvider,
            baseUri: "https://api.example.com{$basePath}",
        );
        $client->sendRequest(new Request('GET', $requestPath));

        self::assertSame($expected, $recordingClient->captured?->getUri()->getPath());
    }

    /**
     * A host-only base URI (no path component in baseUri at all) with a
     * relative-reference request path must still resolve to a genuine
     * absolute path, not silently stay relative — an authority-bearing
     * URI's path must be empty or slash-prefixed.
     */
    public function test_a_host_only_base_uri_with_a_relative_request_path_resolves_to_an_absolute_path(): void
    {
        $recordingClient = new RecordingClient();
        $credentialProvider = new FixedCredentialProvider(new Credentials('AKIDEXAMPLE', 'secret'));

        $client = new SigV4SigningClient(
            $recordingClient,
            'us-east-1',
            'execute-api',
            $credentialProvider,
            baseUri: 'https://api.example.com',
        );
        $client->sendRequest(new Request('GET', 'users'));

        self::assertSame('/users', $recordingClient->captured?->getUri()->getPath());
        self::assertSame('api.example.com', $recordingClient->captured?->getUri()->getHost());
        self::assertSame('api.example.com', $recordingClient->captured?->getHeaderLine('Host'));
    }

    public function test_an_empty_request_path_preserves_the_normalized_base_path(): void
    {
        $recordingClient = new RecordingClient();
        $credentialProvider = new FixedCredentialProvider(new Credentials('AKIDEXAMPLE', 'secret'));

        $client = new SigV4SigningClient(
            $recordingClient,
            'us-east-1',
            'execute-api',
            $credentialProvider,
            baseUri: 'https://api.example.com/prod/',
        );
        $client->sendRequest(new Request('GET', ''));

        self::assertSame('/prod', $recordingClient->captured?->getUri()->getPath());
    }

    public function test_an_empty_request_path_against_a_host_only_base_uri_stays_empty(): void
    {
        $recordingClient = new RecordingClient();
        $credentialProvider = new FixedCredentialProvider(new Credentials('AKIDEXAMPLE', 'secret'));

        $client = new SigV4SigningClient(
            $recordingClient,
            'us-east-1',
            'execute-api',
            $credentialProvider,
            baseUri: 'https://api.example.com',
        );
        $client->sendRequest(new Request('GET', ''));

        self::assertSame('', $recordingClient->captured?->getUri()->getPath());
    }

    public function test_the_request_query_string_survives_base_uri_resolution(): void
    {
        $recordingClient = new RecordingClient();
        $credentialProvider = new FixedCredentialProvider(new Credentials('AKIDEXAMPLE', 'secret'));

        $client = new SigV4SigningClient(
            $recordingClient,
            'us-east-1',
            'execute-api',
            $credentialProvider,
            baseUri: 'https://api.example.com/prod',
        );
        $client->sendRequest(new Request('GET', 'users?active=true&page=2'));

        self::assertSame('/prod/users', $recordingClient->captured?->getUri()->getPath());
        self::assertSame('active=true&page=2', $recordingClient->captured?->getUri()->getQuery());
    }

    /**
     * PSR-7 explicitly permits a stream that cannot be seeked at all
     * (StreamInterface::isSeekable() === false) — a chunked HTTP request
     * body, or a pipe, are real examples. The previous implementation
     * unconditionally called rewind() on the original body after reading
     * it, which throws for exactly this stream, so this class couldn't
     * sign a request carrying one at all.
     */
    public function test_a_non_seekable_request_body_is_signed_and_sent_without_throwing(): void
    {
        $recordingClient = new RecordingClient();
        $credentialProvider = new FixedCredentialProvider(new Credentials('AKIDEXAMPLE', 'secret'));

        $client = new SigV4SigningClient($recordingClient, 'us-east-1', 'service', $credentialProvider);
        $response = $client->sendRequest(
            (new Request('POST', 'https://example.amazonaws.com/'))
                ->withBody(new NonSeekableStream('{"query":{"match_all":{}}}')),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Signature=', $recordingClient->captured?->getHeaderLine('Authorization') ?? '');
        self::assertSame('{"query":{"match_all":{}}}', $recordingClient->captured?->getBody()->getContents());
    }

    /**
     * `SpooledStream` is backed by `php://temp`, which PHP keeps in
     * memory only up to 2MB before spilling to a real temp file — this
     * confirms a body past that boundary still round-trips correctly
     * through both the signing step and the wrapped client, not just
     * that construction doesn't throw.
     */
    public function test_a_large_request_body_past_the_in_memory_threshold_is_signed_and_sent_correctly(): void
    {
        $recordingClient = new RecordingClient();
        $credentialProvider = new FixedCredentialProvider(new Credentials('AKIDEXAMPLE', 'secret'));
        $largeBody = str_repeat('a', 3 * 1024 * 1024);

        $client = new SigV4SigningClient($recordingClient, 'us-east-1', 'service', $credentialProvider);
        $client->sendRequest(new Request('POST', 'https://example.amazonaws.com/', [], $largeBody));

        self::assertStringContainsString('Signature=', $recordingClient->captured?->getHeaderLine('Authorization') ?? '');
        self::assertSame($largeBody, $recordingClient->captured?->getBody()->getContents());
    }

    /**
     * The stream a caller built their request with is the same object
     * this class reads from to compute the signature — rewinding and
     * reading it is a real, visible mutation on the caller's own object,
     * not a private copy, unless the original position is explicitly
     * restored afterward. A first version of this fix left the caller's
     * stream sitting at end-of-body after signing regardless of where it
     * started — reproduced here directly: a 6-byte body seeked to offset
     * 2 before sendRequest() must still read offset 2 afterward.
     */
    public function test_a_seekable_request_bodys_original_cursor_position_is_restored_after_signing(): void
    {
        $recordingClient = new RecordingClient();
        $credentialProvider = new FixedCredentialProvider(new Credentials('AKIDEXAMPLE', 'secret'));

        $request = new Request('POST', 'https://example.amazonaws.com/', [], 'abcdef');
        $body = $request->getBody();
        $body->seek(2);

        $client = new SigV4SigningClient($recordingClient, 'us-east-1', 'service', $credentialProvider);
        $client->sendRequest($request);

        self::assertSame(2, $body->tell());
        // Restoring the caller's own cursor doesn't come at the cost of
        // the signed request itself being wrong -- the wrapped client
        // still receives the full, correct body regardless of where the
        // original stream was left positioned.
        self::assertSame('abcdef', $recordingClient->captured?->getBody()->getContents());
    }

    /**
     * `SigV4SigningClient` itself implements `Psr\Http\Client\ClientInterface`,
     * so any failure it produces while resolving credentials/URI/body/
     * signing — before it ever delegates to the wrapped client — must
     * itself be a valid PSR-18 exception, catchable the same way a
     * caller already catches a failure from the wrapped client. The
     * original, more specific cause (here, `SigningException`) is
     * preserved as `getPrevious()`.
     */
    public function test_no_resolvable_credentials_is_wrapped_in_an_unsignable_request_exception(): void
    {
        $recordingClient = new RecordingClient();
        $credentialProvider = new FixedCredentialProvider(null);

        $client = new SigV4SigningClient($recordingClient, 'us-east-1', 'service', $credentialProvider);
        $request = new Request('GET', 'https://example.amazonaws.com/');

        try {
            $client->sendRequest($request);

            self::fail('Expected an UnsignableRequestException to be thrown.');
        } catch (UnsignableRequestException $e) {
            self::assertInstanceOf(ClientExceptionInterface::class, $e);
            self::assertSame($request, $e->getRequest());
            self::assertSame(UnsignableRequestException::MESSAGE, $e->getMessage());

            $previous = $e->getPrevious();
            self::assertInstanceOf(SigningException::class, $previous);
            // The full message, not just a leading substring — a
            // leading-substring check alone doesn't catch a mutation
            // that only reorders or drops words later in the
            // concatenated string.
            self::assertSame(
                'Could not resolve AWS credentials to sign this request. Set '
                . 'AWS_ACCESS_KEY_ID/AWS_SECRET_ACCESS_KEY, a shared credentials '
                . 'file, or run somewhere with an IAM role attached, or pass a '
                . 'CredentialProvider directly.',
                $previous->getMessage(),
            );
        }
    }

    /**
     * A `CredentialProvider` implementation is entirely the caller's
     * own — it may throw for its own reasons (a network failure fetching
     * an IAM role, a malformed shared-credentials file, ...). Whatever
     * it throws, of whatever type, is still a processing failure this
     * class must catch and wrap exactly like its own internal ones.
     */
    public function test_a_credential_provider_that_throws_is_wrapped_in_an_unsignable_request_exception(): void
    {
        $recordingClient = new RecordingClient();
        $credentialProvider = new ThrowingCredentialProvider();

        $client = new SigV4SigningClient($recordingClient, 'us-east-1', 'service', $credentialProvider);
        $request = new Request('GET', 'https://example.amazonaws.com/');

        try {
            $client->sendRequest($request);

            self::fail('Expected an UnsignableRequestException to be thrown.');
        } catch (UnsignableRequestException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertSame(UnsignableRequestException::MESSAGE, $e->getMessage());

            $previous = $e->getPrevious();
            self::assertInstanceOf(RuntimeException::class, $previous);
            self::assertSame('Simulated credential resolution failure.', $previous->getMessage());
        }
    }

    /**
     * A request with no host of its own, sent through a client with no
     * baseUri configured, has no way to become an absolute URI — it
     * reaches AsyncAws's own Request::setEndpoint() unmodified and fails
     * there, with an AsyncAws-native exception type (not one of this
     * package's own) this class still has to catch and wrap like any
     * other processing failure, without leaking the failing path into
     * its own fixed diagnostic.
     */
    public function test_a_relative_request_target_with_no_usable_base_uri_is_wrapped(): void
    {
        $recordingClient = new RecordingClient();
        $credentialProvider = new FixedCredentialProvider(new Credentials('AKIDEXAMPLE', 'secret'));

        $client = new SigV4SigningClient($recordingClient, 'us-east-1', 'service', $credentialProvider);
        $request = new Request('GET', '/users');

        try {
            $client->sendRequest($request);

            self::fail('Expected an UnsignableRequestException to be thrown.');
        } catch (UnsignableRequestException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertSame(UnsignableRequestException::MESSAGE, $e->getMessage());
            self::assertNotNull($e->getPrevious());
            self::assertStringNotContainsString('/users', $e->getMessage());
        }
    }

    /**
     * A stream that fails while its body is being captured for signing
     * hits the identical shared try/catch boundary in sendRequest() that
     * a genuine mid-signing failure would — body capture and signing are
     * both inside the same block, so this exercises the boundary itself
     * rather than any one specific step of it.
     */
    public function test_a_body_capture_failure_is_wrapped_in_an_unsignable_request_exception(): void
    {
        $recordingClient = new RecordingClient();
        $credentialProvider = new FixedCredentialProvider(new Credentials('AKIDEXAMPLE', 'secret'));

        $client = new SigV4SigningClient($recordingClient, 'us-east-1', 'service', $credentialProvider);
        $request = new Request('POST', 'https://example.amazonaws.com/', [], new ThrowingStream());

        try {
            $client->sendRequest($request);

            self::fail('Expected an UnsignableRequestException to be thrown.');
        } catch (UnsignableRequestException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertSame(UnsignableRequestException::MESSAGE, $e->getMessage());

            $previous = $e->getPrevious();
            self::assertInstanceOf(RuntimeException::class, $previous);
            self::assertSame('Simulated body read failure.', $previous->getMessage());
        }
    }

    /**
     * A real failure from the wrapped client itself — a genuine network
     * error, say — must reach the caller completely unmodified, by
     * identity: this class must never catch and reclassify a failure
     * that was never its own processing failure. The try/catch in
     * sendRequest() deliberately ends before this call, not around it.
     */
    public function test_a_wrapped_client_exception_passes_through_by_identity(): void
    {
        $sentinel = new SentinelClientException('A real network failure from the wrapped client.');
        $throwingClient = new ThrowingClient($sentinel);
        $credentialProvider = new FixedCredentialProvider(new Credentials('AKIDEXAMPLE', 'secret'));

        $client = new SigV4SigningClient($throwingClient, 'us-east-1', 'service', $credentialProvider);

        try {
            $client->sendRequest(new Request('GET', 'https://example.amazonaws.com/'));

            self::fail("Expected the wrapped client's own exception to be thrown.");
        } catch (SentinelClientException $e) {
            self::assertSame($sentinel, $e);
        }
    }
}

final class RecordingClient implements ClientInterface
{
    public ?RequestInterface $captured = null;

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->captured = $request;

        return new Response(200);
    }
}

final class FixedCredentialProvider implements CredentialProvider
{
    public function __construct(private readonly ?Credentials $credentials) {}

    public function getCredentials(Configuration $configuration): ?Credentials
    {
        return $this->credentials;
    }
}

final class ThrowingCredentialProvider implements CredentialProvider
{
    public function getCredentials(Configuration $configuration): ?Credentials
    {
        throw new RuntimeException('Simulated credential resolution failure.');
    }
}

/**
 * A sentinel exception type distinct from anything this package itself
 * throws, so a test can prove — by object identity, not just by
 * catching "some" exception — that a real failure from the wrapped
 * client passes through SigV4SigningClient::sendRequest() completely
 * unmodified.
 */
final class SentinelClientException extends RuntimeException implements ClientExceptionInterface
{
}

final class ThrowingClient implements ClientInterface
{
    public function __construct(private readonly Throwable $exception) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        throw $this->exception;
    }
}

/**
 * A real, sequential-read-only PSR-7 stream — isSeekable() genuinely
 * reports false, and both seek()/rewind() throw exactly as a real
 * non-seekable implementation's would, rather than merely asserting the
 * interface without honoring its own seekability contract.
 */
final class NonSeekableStream implements StreamInterface
{
    private int $position = 0;

    public function __construct(private readonly string $contents) {}

    public function __toString(): string
    {
        return $this->getContents();
    }

    public function close(): void {}

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return strlen($this->contents);
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->position >= strlen($this->contents);
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('This stream is not seekable.');
    }

    public function rewind(): void
    {
        throw new RuntimeException('This stream is not seekable.');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('This stream is not writable.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        $chunk = substr($this->contents, $this->position, $length);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function getContents(): string
    {
        $remaining = substr($this->contents, $this->position);
        $this->position = strlen($this->contents);

        return $remaining;
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $key === null ? [] : null;
    }
}

/**
 * A seekable stream whose getContents() always throws — simulating a
 * genuine body-read failure (a network-backed stream losing its
 * connection mid-read, for instance), distinct from the non-seekable
 * case NonSeekableStream already covers.
 */
final class ThrowingStream implements StreamInterface
{
    public function __toString(): string
    {
        return '';
    }

    public function close(): void {}

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return 0;
    }

    public function eof(): bool
    {
        return false;
    }

    public function isSeekable(): bool
    {
        return true;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void {}

    public function rewind(): void {}

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('This stream is not writable.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        throw new RuntimeException('Simulated body read failure.');
    }

    public function getContents(): string
    {
        throw new RuntimeException('Simulated body read failure.');
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $key === null ? [] : null;
    }
}
