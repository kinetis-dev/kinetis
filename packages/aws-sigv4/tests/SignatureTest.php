<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4\Tests;

use Kinetis\AwsSigV4\Signature;
use Kinetis\AwsSigV4\SigV4SigningClient;
use Kinetis\AwsSigV4\Tests\Support\FixedCredentialProvider;
use Kinetis\AwsSigV4\Tests\Support\RecordingTransport;
use Kinetis\AwsSigV4\WireTarget;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The signing algorithm, against AWS's own published SigV4 test
 * vectors.
 *
 * Every case in vectorProvider() is one vector: its request sent
 * through a client pinned to the vectors' date, credentials, region and
 * service name, and the `Authorization` header AWS publishes for it
 * asserted byte for byte. That header covers the whole canonical
 * request — method, URI, query, headers, signed-header list and payload
 * hash — so a case is evidence about the canonicalization rule rather
 * than a restatement of what this package computed.
 *
 * A vector whose request line carries a character this package
 * percent-encodes before signing — the raw space of `get-space`, the
 * raw UTF-8 of `get-utf8` — describes a request line no client of this
 * package sends, so its published `Authorization` header covers a
 * target that never goes out. Those vectors are evidence here for the
 * canonical URI instead, against the published canonical line and the
 * path the vector's own request line holds.
 *
 * The canonical URI and query are asserted as the strings they are: a
 * signature says nothing about which of two inputs produced it, and two
 * targets that must not collide are only shown apart by the two
 * canonical requests they build.
 */
final class SignatureTest extends TestCase
{
    private const string ORIGIN = 'https://example.amazonaws.com';

    private const string VECTOR_DATE = '2015-08-30T12:36:00Z';

    private const string CREDENTIAL_SCOPE = 'AKIDEXAMPLE/20150830/us-east-1/service/aws4_request';

    /**
     * @return iterable<string, array{
     *     method: string,
     *     target: string,
     *     headers: array<string, string|list<string>>,
     *     body: string,
     *     signedHeaders: string,
     *     signature: string,
     * }>
     */
    public static function vectorProvider(): iterable
    {
        yield 'get-vanilla' => [
            'method' => 'GET',
            'target' => '/',
            'headers' => [],
            'body' => '',
            'signedHeaders' => 'host;x-amz-date',
            'signature' => '5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
        ];
        yield 'get-unreserved' => [
            'method' => 'GET',
            'target' => '/-._~0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz',
            'headers' => [],
            'body' => '',
            'signedHeaders' => 'host;x-amz-date',
            'signature' => '07ef7494c76fa4850883e2b006601f940f8a34d404d0cfa977f52a65bbf5f24f',
        ];
        yield 'get-slashes' => [
            'method' => 'GET',
            'target' => '//example//',
            'headers' => [],
            'body' => '',
            'signedHeaders' => 'host;x-amz-date',
            'signature' => '9a624bd73a37c9a373b5312afbebe7a714a789de108f0bdfe846570885f57e84',
        ];
        yield 'get-vanilla-query-order-key-case' => [
            'method' => 'GET',
            'target' => '/?Param2=value2&Param1=value1',
            'headers' => [],
            'body' => '',
            'signedHeaders' => 'host;x-amz-date',
            'signature' => 'b97d918cfa904a5beff61c982a1b6f458b799221646efd99d3219ec94cdf2500',
        ];
        yield 'get-vanilla-query-order-value' => [
            'method' => 'GET',
            'target' => '/?Param1=value2&Param1=value1',
            'headers' => [],
            'body' => '',
            'signedHeaders' => 'host;x-amz-date',
            'signature' => '5772eed61e12b33fae39ee5e7012498b51d56abc0abb7c60486157bd471c4694',
        ];
        yield 'get-vanilla-utf8-query' => [
            'method' => 'GET',
            'target' => "/?\u{1234}=bar",
            'headers' => [],
            'body' => '',
            'signedHeaders' => 'host;x-amz-date',
            'signature' => '2cdec8eed098649ff3a119c94853b13c643bcf08f8b0a1d91e12c9027818dd04',
        ];
        yield 'get-header-value-order' => [
            'method' => 'GET',
            'target' => '/',
            'headers' => ['My-Header1' => ['value4', 'value1', 'value3', 'value2']],
            'body' => '',
            'signedHeaders' => 'host;my-header1;x-amz-date',
            'signature' => '08c7e5a9acfcfeb3ab6b2185e75ce8b1deb5e634ec47601a50643f830c755c01',
        ];
        yield 'get-header-key-duplicate' => [
            'method' => 'GET',
            'target' => '/',
            'headers' => ['My-Header1' => ['value2', 'value2', 'value1']],
            'body' => '',
            'signedHeaders' => 'host;my-header1;x-amz-date',
            'signature' => 'c9d5ea9f3f72853aea855b47ea873832890dbdd183b4468f858259531a5138ea',
        ];
        yield 'get-header-value-trim' => [
            'method' => 'GET',
            'target' => '/',
            'headers' => ['My-Header1' => ' value1', 'My-Header2' => '"a   b   c"'],
            'body' => '',
            'signedHeaders' => 'host;my-header1;my-header2;x-amz-date',
            'signature' => 'acc3ed3afb60bb290fc8d2dd0098b9911fcaa05412b367055dee359757a9c736',
        ];
        yield 'post-x-www-form-urlencoded' => [
            'method' => 'POST',
            'target' => '/',
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => 'Param1=value1',
            'signedHeaders' => 'content-type;host;x-amz-date',
            'signature' => 'ff11897932ad3f4e8b18135d722051e5ac45fc38421b1da7b9d196a0fe09473a',
        ];
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    #[DataProvider('vectorProvider')]
    public function test_a_published_vector_signs_to_its_published_authorization_header(
        string $method,
        string $target,
        array $headers,
        string $body,
        string $signedHeaders,
        string $signature,
    ): void {
        $transport = new RecordingTransport();

        self::vectorClient($transport, FixedCredentialProvider::example())
            ->sendRequest(new Request($method, self::ORIGIN . $target, $headers, $body));

        self::assertSame(
            'AWS4-HMAC-SHA256 Credential=' . self::CREDENTIAL_SCOPE . ', '
            . 'SignedHeaders=' . $signedHeaders . ', Signature=' . $signature,
            $transport->headerLineOfCall(0, 'Authorization'),
        );
    }

    /**
     * A session token is signed as `X-Amz-Security-Token` and reaches
     * the wire, which the vector's own signed-header list requires.
     */
    public function test_the_session_token_vector_signs_the_security_token_header(): void
    {
        $token = '6e86291e8372ff2a2260956d9b8aae1d763fbf315fa00fa31553b73ebf194267';
        $transport = new RecordingTransport();

        self::vectorClient($transport, FixedCredentialProvider::withSessionToken($token))
            ->sendRequest(new Request('GET', self::ORIGIN . '/'));

        self::assertSame(
            'AWS4-HMAC-SHA256 Credential=' . self::CREDENTIAL_SCOPE . ', '
            . 'SignedHeaders=host;x-amz-date;x-amz-security-token, '
            . 'Signature=07ec1639c89043aa0e3e2de82b96708f198cceab042d4a97044c66dd9f74e7f8',
            $transport->headerLineOfCall(0, 'Authorization'),
        );
        self::assertSame($token, $transport->headerLineOfCall(0, 'X-Amz-Security-Token'));
    }

    /**
     * A caller's own `X-Amz-Content-Sha256` is replaced by the hash of
     * the body being sent, so the value a service reads as the payload
     * hash is the one the canonical request was built from. It is
     * signed: the header goes out, so it belongs in the signature.
     */
    public function test_a_caller_payload_hash_header_is_overwritten_with_the_body_hash(): void
    {
        $transport = new RecordingTransport();
        $body = 'Param1=value1';

        self::vectorClient($transport, FixedCredentialProvider::example())->sendRequest(
            new Request('POST', self::ORIGIN . '/', ['X-Amz-Content-Sha256' => str_repeat('0', 64)], $body),
        );

        self::assertSame(
            [hash('sha256', $body)],
            $transport->headersOfCall(0)['x-amz-content-sha256'] ?? [],
        );
        self::assertStringContainsString(
            'SignedHeaders=host;x-amz-content-sha256;x-amz-date,',
            $transport->headerLineOfCall(0, 'Authorization'),
        );
    }

    /**
     * Every spelling and every repeat the caller supplied collapses to
     * the one value, so no second `X-Amz-Content-Sha256` travels beside
     * the signed one.
     */
    public function test_repeated_and_differently_cased_payload_hash_headers_collapse_to_one(): void
    {
        $transport = new RecordingTransport();
        $body = 'Param1=value1';

        self::vectorClient($transport, FixedCredentialProvider::example())->sendRequest(
            new Request('PUT', self::ORIGIN . '/', [
                'x-amz-content-sha256' => ['UNSIGNED-PAYLOAD', str_repeat('a', 64)],
                'X-AMZ-CONTENT-SHA256' => str_repeat('b', 64),
            ], $body),
        );

        self::assertSame(
            [hash('sha256', $body)],
            $transport->headersOfCall(0)['x-amz-content-sha256'] ?? [],
        );
    }

    /**
     * The header is owned only where the caller set it: a request
     * without one is signed and sent without one.
     */
    public function test_no_payload_hash_header_is_added_to_a_request_without_one(): void
    {
        $transport = new RecordingTransport();

        self::vectorClient($transport, FixedCredentialProvider::example())
            ->sendRequest(new Request('POST', self::ORIGIN . '/', [], 'Param1=value1'));

        self::assertArrayNotHasKey('x-amz-content-sha256', $transport->headersOfCall(0));
        self::assertStringContainsString(
            'SignedHeaders=host;x-amz-date,',
            $transport->headerLineOfCall(0, 'Authorization'),
        );
    }

    /**
     * @return iterable<string, array{path: string, canonical: string}>
     */
    public static function publishedCanonicalUriProvider(): iterable
    {
        yield 'get-space' => [
            'path' => '/example space/',
            'canonical' => '/example%20space/',
        ];
        yield 'get-utf8' => [
            'path' => "/\u{1234}",
            'canonical' => '/%E1%88%B4',
        ];
        yield 'get-slashes' => [
            'path' => '/example/',
            'canonical' => '/example/',
        ];
        yield 'get-unreserved' => [
            'path' => '/-._~0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz',
            'canonical' => '/-._~0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz',
        ];
    }

    /**
     * The canonical URI line AWS publishes for a vector, against the
     * path that vector's own request line carries.
     */
    #[DataProvider('publishedCanonicalUriProvider')]
    public function test_a_published_vector_path_canonicalizes_to_its_published_uri_line(
        string $path,
        string $canonical,
    ): void {
        $canonicalUri = new ReflectionMethod(Signature::class, 'canonicalUri');

        self::assertSame($canonical, $canonicalUri->invoke(null, $path));
    }

    /**
     * The two targets travel as the two different request lines they
     * are, and the canonical text keeps them apart — `/a%252Fb` against
     * `/a/b` — so one signature cannot be replayed against the other
     * target.
     */
    public function test_an_encoded_slash_signs_apart_from_a_literal_slash(): void
    {
        $encoded = new RecordingTransport();
        $literal = new RecordingTransport();

        self::vectorClient($encoded, FixedCredentialProvider::example())
            ->sendRequest(new Request('GET', self::ORIGIN . '/a%2Fb'));
        self::vectorClient($literal, FixedCredentialProvider::example())
            ->sendRequest(new Request('GET', self::ORIGIN . '/a/b'));

        self::assertSame(self::ORIGIN . '/a%2Fb', $encoded->urlOfCall(0));
        self::assertSame(self::ORIGIN . '/a/b', $literal->urlOfCall(0));
        self::assertNotSame(
            $encoded->headerLineOfCall(0, 'Authorization'),
            $literal->headerLineOfCall(0, 'Authorization'),
        );
    }

    /**
     * @return iterable<string, array{path: string, canonical: string}>
     */
    public static function canonicalUriProvider(): iterable
    {
        yield 'an encoded slash stays inside its segment' => [
            'path' => '/a%2Fb',
            'canonical' => '/a%252Fb',
        ];
        yield 'a literal slash separates two segments' => [
            'path' => '/a/b',
            'canonical' => '/a/b',
        ];
        yield 'a literal percent is one escape' => [
            'path' => '/100%25',
            'canonical' => '/100%2525',
        ];
        yield 'a space is an escape' => [
            'path' => '/example space/',
            'canonical' => '/example%2520space/',
        ];
        yield 'utf-8 encodes byte by byte' => [
            'path' => "/\u{1234}",
            'canonical' => '/%25E1%2588%25B4',
        ];
        yield 'repeated slashes collapse to one' => [
            'path' => '//example//',
            'canonical' => '/example/',
        ];
        yield 'a dot-looking segment is an ordinary segment' => [
            'path' => '/a/..b/.c',
            'canonical' => '/a/..b/.c',
        ];
        yield 'an encoded dot segment is resolved' => [
            'path' => '/a/%2E%2E/b',
            'canonical' => '/b',
        ];
        yield 'an empty path is the root' => [
            'path' => '',
            'canonical' => '/',
        ];
    }

    /**
     * The canonical URI is what a request target signs as: the wire form
     * {@see WireTarget} produces, each segment percent-encoded once. An
     * encoded slash names data inside a segment and a literal one names
     * a separator, so `/a%2Fb` and `/a/b` cannot canonicalize alike.
     */
    #[DataProvider('canonicalUriProvider')]
    public function test_a_request_path_canonicalizes_to_its_wire_segments(
        string $path,
        string $canonical,
    ): void {
        $canonicalUri = new ReflectionMethod(Signature::class, 'canonicalUri');

        self::assertSame($canonical, $canonicalUri->invoke(null, WireTarget::normalizePath($path)));
    }

    /**
     * @return iterable<string, array{query: string, canonical: string}>
     */
    public static function canonicalQueryProvider(): iterable
    {
        yield 'names sort before values' => [
            'query' => 'Param2=value2&Param1=value1',
            'canonical' => 'Param1=value1&Param2=value2',
        ];
        // Bytewise, not numeric: "1" precedes "9".
        yield 'a repeated name sorts on its encoded values' => [
            'query' => 'a=10&a=9',
            'canonical' => 'a=10&a=9',
        ];
        yield 'a repeated name whose values sort against an escape' => [
            'query' => 'x=a.b&x=a%2Fb',
            'canonical' => 'x=a%2Fb&x=a.b',
        ];
        yield 'a duplicate pair is kept' => [
            'query' => 'a=1&a=1',
            'canonical' => 'a=1&a=1',
        ];
        yield 'a plus is a plus' => [
            'query' => 'q=a+b',
            'canonical' => 'q=a%2Bb',
        ];
        yield 'a space is an escape' => [
            'query' => 'q=a%20b',
            'canonical' => 'q=a%20b',
        ];
        yield 'a name with no value takes an empty one' => [
            'query' => 'b&a=1',
            'canonical' => 'a=1&b=',
        ];
        yield 'a value keeps its own equals sign' => [
            'query' => 'a=1=2',
            'canonical' => 'a=1%3D2',
        ];
        yield 'an unreserved escape decodes' => [
            'query' => 'q=%7Ea',
            'canonical' => 'q=~a',
        ];
        yield 'an empty query stays empty' => [
            'query' => '',
            'canonical' => '',
        ];
    }

    /**
     * The canonical query is built from the wire query's own bytes, and
     * a signature says nothing about which of two orderings produced
     * it — so the string itself is what is asserted. Duplicates survive,
     * ordering is bytewise on the encoded pair, and a `+` is the plus
     * character it stands for on the wire rather than a space.
     */
    #[DataProvider('canonicalQueryProvider')]
    public function test_the_canonical_query_is_built_from_the_wire_query(
        string $query,
        string $canonical,
    ): void {
        $canonicalQuery = new ReflectionMethod(Signature::class, 'canonicalQuery');

        self::assertSame($canonical, $canonicalQuery->invoke(null, $query));
    }

    private static function vectorClient(
        RecordingTransport $transport,
        FixedCredentialProvider $credentials,
    ): SigV4SigningClient {
        return new SigV4SigningClient(
            self::ORIGIN,
            'us-east-1',
            'service',
            $credentials,
            new \DateTimeImmutable(self::VECTOR_DATE),
            $transport->asTransport(),
        );
    }
}
