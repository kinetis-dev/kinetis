<?php

declare(strict_types=1);

namespace Kinetis\BrefAdapter\Tests;

use Kinetis\BrefAdapter\BrefLambdaAdapter;
use Kinetis\BrefAdapter\Exception\BrefAdapterException;
use Kinetis\BrefAdapter\Exception\MalformedRequestBodyException;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What only a Lambda event can exhibit, with no cross-adapter contract
 * to hold it to. Everything an adapter shares with the others — request
 * line, identity, headers, cookies, the client address, form/JSON/binary
 * bodies, the form-complexity ceilings, response headers and cookies,
 * streaming, the malformed-body 400 — lives in the runtime conformance
 * suite, run against this adapter by {@see LambdaConformanceTest}, which
 * also holds the Lambda-only malformed-base64 input to that suite's 400
 * contract.
 */
final class BrefLambdaAdapterTest extends TestCase
{
    private const string DOMAIN = 'kinetis.execute-api.eu-west-1.amazonaws.com';

    public function test_is_persistent(): void
    {
        self::assertTrue((new BrefLambdaAdapter('127.0.0.1:9001'))->isPersistent());
    }

    /**
     * API Gateway may base64-encode any body, text included — not only
     * the binary ones the conformance suite sends that way.
     */
    public function test_decodes_a_base64_encoded_body(): void
    {
        $request = BrefLambdaAdapter::requestFromEvent(self::event([
            'rawPath' => '/users',
            'body' => base64_encode('{"name":"Alon"}'),
            'isBase64Encoded' => true,
        ], method: 'POST'));

        self::assertSame('{"name":"Alon"}', (string) $request->getBody());
    }

    /**
     * A multipart body is decoded like any other, and handed on as the
     * exact bytes the client sent — nothing here parses it, so what
     * reaches the Kernel is what RequestBodyMiddleware would have met
     * under any other runtime.
     */
    public function test_a_base64_encoded_multipart_body_is_decoded_and_handed_on_intact(): void
    {
        $boundary = 'KinetisTestBoundary';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"name\"\r\n\r\n"
            . "Alon\r\n"
            . "--{$boundary}--\r\n";

        $request = BrefLambdaAdapter::requestFromEvent(self::event([
            'rawPath' => '/avatars',
            'headers' => ['content-type' => "multipart/form-data; boundary={$boundary}"],
            'body' => base64_encode($body),
            'isBase64Encoded' => true,
        ], method: 'POST'));

        self::assertSame($body, (string) $request->getBody());
        self::assertNull($request->getParsedBody());
    }

    public function test_a_missing_source_ip_leaves_remote_addr_unset(): void
    {
        $event = self::event();
        unset($event['requestContext']['http']['sourceIp']);

        self::assertArrayNotHasKey('REMOTE_ADDR', BrefLambdaAdapter::requestFromEvent($event)->getServerParams());
    }

    // --- Strict base64 decoding: invalid input is reported, and a
    // genuinely decoded "" or "0" is never confused with either. ---

    public function test_a_malformed_base64_body_throws_instead_of_becoming_an_empty_body(): void
    {
        $this->expectException(MalformedRequestBodyException::class);

        BrefLambdaAdapter::requestFromEvent(self::event([
            'body' => 'not valid base64 !!! ***',
            'isBase64Encoded' => true,
        ], method: 'POST'));
    }

    public function test_a_base64_body_that_decodes_to_the_literal_zero_is_not_treated_as_empty(): void
    {
        $request = BrefLambdaAdapter::requestFromEvent(self::event([
            'body' => base64_encode('0'),
            'isBase64Encoded' => true,
        ], method: 'POST'));

        self::assertSame('0', (string) $request->getBody());
    }

    public function test_a_base64_body_that_decodes_to_an_empty_string_round_trips_correctly(): void
    {
        $request = BrefLambdaAdapter::requestFromEvent(self::event([
            'body' => base64_encode(''),
            'isBase64Encoded' => true,
        ], method: 'POST'));

        self::assertSame('', (string) $request->getBody());
    }

    // --- Request identity, from the one authoritative boundary ---------

    /**
     * `requestContext.domainName` decides the host, the forwarded
     * headers decide the scheme and port, and the `Host` header the
     * application reads is rebuilt from those rather than copied from
     * the event — so the URI and the header cannot disagree.
     */
    public function test_the_uri_and_host_header_are_both_built_from_the_domain_name(): void
    {
        $request = BrefLambdaAdapter::requestFromEvent(self::event([
            'rawPath' => '/users/42',
            'rawQueryString' => 'tag=a+b&tag=c',
            'headers' => ['x-forwarded-proto' => 'https', 'x-forwarded-port' => '443'],
        ]));

        self::assertSame('https', $request->getUri()->getScheme());
        self::assertSame(self::DOMAIN, $request->getUri()->getHost());
        self::assertNull($request->getUri()->getPort(), '443 is the default port for https');
        self::assertSame([self::DOMAIN], $request->getHeader('Host'));
        self::assertSame('/users/42?tag=a+b&tag=c', $request->getRequestTarget());
        self::assertSame('1.1', $request->getProtocolVersion());
    }

    public function test_a_non_default_forwarded_port_reaches_the_uri_and_the_host_header(): void
    {
        $request = BrefLambdaAdapter::requestFromEvent(self::event([
            'headers' => ['x-forwarded-port' => '8443'],
        ]));

        self::assertSame(8443, $request->getUri()->getPort());
        self::assertSame([self::DOMAIN . ':8443'], $request->getHeader('Host'));
    }

    /**
     * An HTTP API and a Function URL are TLS-only, so the scheme is a
     * platform fact and `x-forwarded-proto` is only checked against it.
     * That check is a comparison, so it has to hold for the value cased
     * and padded the way a header value may be — a spelling the shared
     * conformance suite, which sends the exact value, says nothing
     * about.
     */
    public function test_the_gateways_own_scheme_is_matched_however_the_header_spells_it(): void
    {
        $request = BrefLambdaAdapter::requestFromEvent(self::event(['headers' => ['x-forwarded-proto' => ' HTTPS ']]));

        self::assertSame('https', $request->getUri()->getScheme());
        self::assertSame([self::DOMAIN], $request->getHeader('Host'), '443 is the default port for https, so it is not part of the authority');
    }

    /**
     * The raw query is what the parameters come from — not the event's
     * own `queryStringParameters`, which comma-joins a repeated
     * parameter into one value PHP would then read as a single
     * parameter containing a comma.
     */
    public function test_query_parameters_come_from_the_raw_query_not_the_gateways_summary_of_it(): void
    {
        $request = BrefLambdaAdapter::requestFromEvent(self::event([
            'rawQueryString' => 'tag=a&tag=b',
            'queryStringParameters' => ['tag' => 'a,b'],
        ]));

        self::assertSame(['tag' => 'b'], $request->getQueryParams());
        self::assertSame('tag=a&tag=b', $request->getUri()->getQuery());
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function contradictoryIdentities(): iterable
    {
        yield 'a host header naming another domain' => [
            ['headers' => ['host' => 'evil.example']],
            'different hosts',
        ];

        yield 'a host header and a forwarded port that disagree' => [
            ['headers' => ['host' => self::DOMAIN . ':8443', 'x-forwarded-port' => '443']],
            'different ports',
        ];

        // A forwarded scheme naming plaintext belongs to the shared
        // conformance suite, which asserts it against every adapter —
        // see LambdaConformanceTest.
        yield 'a forwarded scheme that is not a scheme this platform serves' => [
            ['headers' => ['x-forwarded-proto' => 'gopher']],
            'x-forwarded-proto',
        ];

        yield 'a forwarded port that is not a number' => [
            ['headers' => ['x-forwarded-port' => 'eighty']],
            'x-forwarded-port',
        ];

        yield 'a raw path carrying its own query' => [
            ['rawPath' => '/users?admin=1'],
            'rawPath',
        ];

        yield 'a raw path that is not absolute' => [
            ['rawPath' => 'users'],
            'rawPath',
        ];

        yield 'a raw path that is not valid UTF-8' => [
            ['rawPath' => "/\xC3\x28"],
            'valid UTF-8',
        ];

        yield 'a raw path carrying a control character' => [
            ['rawPath' => "/users\r\nX-Injected: 1"],
            'control character',
        ];

        yield 'a raw query that is not valid UTF-8' => [
            ['rawQueryString' => "q=\xFF\xFE"],
            'valid UTF-8',
        ];

        yield 'a protocol that is not an HTTP version' => [
            ['protocol' => 'SPDY'],
            'protocol',
        ];

        yield 'a domain name that is not a host' => [
            ['domainName' => 'not a host'],
            'domainName',
        ];
    }

    /**
     * Every one of these describes a request whose identity is not
     * self-consistent, and every one of them would otherwise be
     * dispatched as a plausible request built from whichever fields
     * happened to be usable.
     *
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('contradictoryIdentities')]
    public function test_a_contradictory_or_malformed_identity_is_refused_before_dispatch(array $overrides, string $expected): void
    {
        $this->expectException(BrefAdapterException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expected, '/') . '/');

        BrefLambdaAdapter::requestFromEvent(self::event($overrides));
    }

    // --- Header maps that mean two things at once ----------------------

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function ambiguousHeaderMaps(): iterable
    {
        yield 'two spellings of Host' => [
            ['Host' => 'kinetis.execute-api.eu-west-1.amazonaws.com', 'host' => 'evil.example'],
            'two spellings',
        ];

        yield 'two spellings of a forwarded scheme' => [
            ['X-Forwarded-Proto' => 'https', 'x-forwarded-proto' => 'http'],
            'two spellings',
        ];

        yield 'two spellings of a content length' => [
            ['Content-Length' => '10', 'CONTENT-LENGTH' => '99999'],
            'two spellings',
        ];

        yield 'a header name that is not a token' => [
            ['X Forwarded Proto' => 'https'],
            'RFC 9110 token',
        ];

        yield 'a header value carrying a control character' => [
            ['X-Trace' => "one\r\nX-Injected: 1"],
            'control character',
        ];

        yield 'a header value that is not a string' => [
            ['X-Trace' => ['a', 'b']],
            'string values',
        ];
    }

    /**
     * A canonical payload-v2 event carries each header once. A direct
     * invocation carries whatever its caller wrote, and lowercasing two
     * spellings into one map lets the second silently win — so an event
     * can name two authorities, two forwarded schemes or two content
     * lengths while the identity validator only ever sees the survivor.
     * An ambiguity resolved by key order is not an identity anything
     * downstream can rely on, so it is refused on this public boundary
     * rather than folded.
     *
     * @param array<string, mixed> $headers
     */
    #[DataProvider('ambiguousHeaderMaps')]
    public function test_an_ambiguous_or_malformed_header_map_is_refused_before_dispatch(array $headers, string $expected): void
    {
        $this->expectException(BrefAdapterException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expected, '/') . '/');

        BrefLambdaAdapter::requestFromEvent(self::event(['headers' => $headers]));
    }

    /**
     * The same rule for cookies, which arrive as their own list.
     * Filtering a malformed entry out and handing on the rest would
     * present the client as having sent only those — the
     * silently-shortened shape this framework refuses everywhere else.
     */
    public function test_a_cookie_list_carrying_a_non_string_is_refused_rather_than_filtered(): void
    {
        $this->expectException(BrefAdapterException::class);
        $this->expectExceptionMessageMatches('/cookies must be a list of strings/');

        BrefLambdaAdapter::requestFromEvent(self::event(['cookies' => ['a=1', 42, 'b=2']]));
    }

    /**
     * The payload-level detail the conformance suite can't see from the
     * wire: a body that is already valid UTF-8 is sent as-is, with the
     * flag off, rather than needlessly base64-encoded.
     */
    public function test_a_valid_utf8_response_body_is_not_base64_encoded(): void
    {
        $payload = BrefLambdaAdapter::responseToPayload(new Response(200, [], 'café ☕'));

        self::assertFalse($payload['isBase64Encoded']);
        self::assertSame('café ☕', $payload['body']);
    }

    /**
     * A payload-v2 event with every identity field API Gateway really
     * sets, so a test only has to say what it is changing.
     *
     * @param array<string, mixed> $overrides `domainName` and `protocol`
     *     are lifted into requestContext; everything else is a top-level
     *     event field
     * @return array<string, mixed>
     */
    private static function event(array $overrides = [], string $method = 'GET'): array
    {
        $domain = $overrides['domainName'] ?? self::DOMAIN;
        $protocol = $overrides['protocol'] ?? 'HTTP/1.1';
        unset($overrides['domainName'], $overrides['protocol']);

        return [
            'version' => '2.0',
            'rawPath' => '/',
            'rawQueryString' => '',
            'headers' => [],
            'requestContext' => [
                'domainName' => $domain,
                'http' => ['method' => $method, 'protocol' => $protocol, 'sourceIp' => '203.0.113.7'],
            ],
            'body' => '',
            'isBase64Encoded' => false,
            ...$overrides,
        ];
    }
}
