<?php

declare(strict_types=1);

namespace Kinetis\BrefAdapter\Tests;

use Kinetis\BrefAdapter\BrefLambdaAdapter;
use Kinetis\BrefAdapter\Exception\BrefAdapterException;
use Kinetis\Http\StreamedResponse;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BrefLambdaAdapterTest extends TestCase
{
    public function test_is_persistent(): void
    {
        self::assertTrue((new BrefLambdaAdapter('127.0.0.1:9001'))->isPersistent());
    }

    public function test_converts_an_api_gateway_v2_event_into_a_psr7_request(): void
    {
        $event = [
            'rawPath' => '/users/42',
            'rawQueryString' => 'limit=5',
            'headers' => ['content-type' => 'application/json'],
            'queryStringParameters' => ['limit' => '5'],
            'requestContext' => ['http' => ['method' => 'GET']],
            'body' => '',
            'isBase64Encoded' => false,
        ];

        $request = BrefLambdaAdapter::requestFromEvent($event);

        self::assertSame('GET', $request->getMethod());
        self::assertSame('/users/42', $request->getUri()->getPath());
        self::assertSame('limit=5', $request->getUri()->getQuery());
        self::assertSame('application/json', $request->getHeaderLine('content-type'));
        self::assertSame(['limit' => '5'], $request->getQueryParams());
    }

    /**
     * A purely-numeric JSON object key ("123") is a real, RFC 9110-valid
     * header name — a token, which includes digits — but
     * json_decode(..., associative: true) coerces it to a genuine PHP
     * int array key, and PSR-7's withHeader() requires a string,
     * throwing otherwise even though nothing about the header itself is
     * invalid. (string) $name genuinely fixes this for withHeader(),
     * since it's a real function-argument cast, not an array key.
     */
    public function test_a_purely_numeric_header_name_is_not_corrupted_by_array_key_coercion(): void
    {
        $event = [
            'rawPath' => '/',
            'headers' => ['123' => 'ok'],
            'requestContext' => ['http' => ['method' => 'GET']],
            'body' => '',
            'isBase64Encoded' => false,
        ];

        $request = BrefLambdaAdapter::requestFromEvent($event);

        self::assertTrue($request->hasHeader('123'));
        self::assertSame('ok', $request->getHeaderLine('123'));
    }

    /**
     * The query-parameter counterpart, confirming the honest (not
     * "fixed") outcome: the same array-key coercion happens here too,
     * but re-keying an array element can't undo it the way casting a
     * function argument can — PHP always coerces a canonical-integer
     * string used as an array key back to int (confirmed directly by
     * reflection here, not assumed) — and withQueryParams() never
     * throws on an int key the way withHeader() throws on an int
     * argument, so nothing is actually broken by it. This test exists to
     * pin that this is a genuine PHP limitation, not something a future
     * change should try to "fix" the same way the header loop was.
     */
    public function test_a_purely_numeric_query_parameter_name_is_still_readable_despite_array_key_coercion(): void
    {
        $event = [
            'rawPath' => '/',
            'queryStringParameters' => ['123' => 'ok'],
            'requestContext' => ['http' => ['method' => 'GET']],
            'body' => '',
            'isBase64Encoded' => false,
        ];

        $request = BrefLambdaAdapter::requestFromEvent($event);
        $queryParams = $request->getQueryParams();

        self::assertSame('ok', $queryParams['123']);
        self::assertIsInt(array_keys($queryParams)[0], 'PHP always coerces this array key to int; a string-key assertion would be false');
    }

    public function test_decodes_a_base64_encoded_body(): void
    {
        $event = [
            'rawPath' => '/users',
            'requestContext' => ['http' => ['method' => 'POST']],
            'body' => base64_encode('{"name":"Alon"}'),
            'isBase64Encoded' => true,
        ];

        $request = BrefLambdaAdapter::requestFromEvent($event);

        self::assertSame('{"name":"Alon"}', (string) $request->getBody());
    }

    public function test_converts_a_psr7_response_into_a_lambda_payload(): void
    {
        $response = new Response(201, ['Content-Type' => 'application/json'], '{"id":1}');

        $payload = BrefLambdaAdapter::responseToPayload($response);

        self::assertSame(201, $payload['statusCode']);
        self::assertSame('application/json', $payload['headers']['Content-Type']);
        self::assertSame('{"id":1}', $payload['body']);
        self::assertFalse($payload['isBase64Encoded']);
    }

    public function test_a_streamed_response_is_rejected_since_the_runtime_api_has_no_partial_delivery(): void
    {
        $response = new StreamedResponse(new Response(200), static function (): void {});

        $this->expectException(RuntimeException::class);

        BrefLambdaAdapter::responseToPayload($response);
    }

    public function test_a_multipart_body_is_parsed_into_fields_and_uploaded_files(): void
    {
        $boundary = 'KinetisTestBoundary';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"name\"\r\n\r\n"
            . "Alon\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"avatar\"; filename=\"avatar.png\"\r\n"
            . "Content-Type: image/png\r\n\r\n"
            . "fake image bytes\r\n"
            . "--{$boundary}--\r\n";

        $event = [
            'rawPath' => '/avatars',
            'headers' => ['content-type' => "multipart/form-data; boundary={$boundary}"],
            'requestContext' => ['http' => ['method' => 'POST']],
            'body' => $body,
            'isBase64Encoded' => false,
        ];

        $request = BrefLambdaAdapter::requestFromEvent($event);

        self::assertSame(['name' => 'Alon'], $request->getParsedBody());

        $files = $request->getUploadedFiles();
        self::assertArrayHasKey('avatar', $files);
        self::assertSame('avatar.png', $files['avatar']->getClientFilename());
        self::assertSame('image/png', $files['avatar']->getClientMediaType());
        self::assertSame('fake image bytes', (string) $files['avatar']->getStream());
    }

    public function test_a_base64_encoded_multipart_body_is_decoded_before_parsing(): void
    {
        $boundary = 'KinetisTestBoundary';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"name\"\r\n\r\n"
            . "Alon\r\n"
            . "--{$boundary}--\r\n";

        $event = [
            'rawPath' => '/avatars',
            'headers' => ['content-type' => "multipart/form-data; boundary={$boundary}"],
            'requestContext' => ['http' => ['method' => 'POST']],
            'body' => base64_encode($body),
            'isBase64Encoded' => true,
        ];

        $request = BrefLambdaAdapter::requestFromEvent($event);

        self::assertSame(['name' => 'Alon'], $request->getParsedBody());
    }

    public function test_a_url_encoded_body_is_parsed_via_parse_str(): void
    {
        $event = [
            'rawPath' => '/avatars',
            'headers' => ['content-type' => 'application/x-www-form-urlencoded'],
            'requestContext' => ['http' => ['method' => 'POST']],
            'body' => 'name=Url+Encoded&limit=5',
            'isBase64Encoded' => false,
        ];

        $request = BrefLambdaAdapter::requestFromEvent($event);

        self::assertSame(['name' => 'Url Encoded', 'limit' => '5'], $request->getParsedBody());
        self::assertSame([], $request->getUploadedFiles());
    }

    public function test_a_json_body_is_left_unparsed(): void
    {
        $event = [
            'rawPath' => '/users',
            'headers' => ['content-type' => 'application/json'],
            'requestContext' => ['http' => ['method' => 'POST']],
            'body' => '{"name":"Alon"}',
            'isBase64Encoded' => false,
        ];

        $request = BrefLambdaAdapter::requestFromEvent($event);

        self::assertNull($request->getParsedBody());
        self::assertSame('{"name":"Alon"}', (string) $request->getBody());
    }

    // --- Cookies and sourceIp: payload format 2.0 carries both outside
    // $headers, and neither reached the request before this fix. ---

    public function test_top_level_cookies_are_reconstructed_into_a_cookie_header_and_cookie_params(): void
    {
        $event = [
            'rawPath' => '/dashboard',
            'requestContext' => ['http' => ['method' => 'GET']],
            'cookies' => ['kinetis_session=abc123', 'theme=dark'],
            'body' => '',
            'isBase64Encoded' => false,
        ];

        $request = BrefLambdaAdapter::requestFromEvent($event);

        self::assertSame('kinetis_session=abc123; theme=dark', $request->getHeaderLine('Cookie'));
        self::assertSame(['kinetis_session' => 'abc123', 'theme' => 'dark'], $request->getCookieParams());
    }

    public function test_an_event_with_no_cookies_produces_no_cookie_header(): void
    {
        $event = [
            'rawPath' => '/dashboard',
            'requestContext' => ['http' => ['method' => 'GET']],
            'body' => '',
            'isBase64Encoded' => false,
        ];

        $request = BrefLambdaAdapter::requestFromEvent($event);

        self::assertSame('', $request->getHeaderLine('Cookie'));
        self::assertSame([], $request->getCookieParams());
    }

    public function test_source_ip_is_mapped_to_remote_addr(): void
    {
        $event = [
            'rawPath' => '/',
            'requestContext' => ['http' => ['method' => 'GET', 'sourceIp' => '203.0.113.7']],
            'body' => '',
            'isBase64Encoded' => false,
        ];

        $request = BrefLambdaAdapter::requestFromEvent($event);

        self::assertSame('203.0.113.7', $request->getServerParams()['REMOTE_ADDR'] ?? null);
    }

    public function test_a_missing_source_ip_leaves_remote_addr_unset(): void
    {
        $event = [
            'rawPath' => '/',
            'requestContext' => ['http' => ['method' => 'GET']],
            'body' => '',
            'isBase64Encoded' => false,
        ];

        $request = BrefLambdaAdapter::requestFromEvent($event);

        self::assertArrayNotHasKey('REMOTE_ADDR', $request->getServerParams());
    }

    // --- Strict base64 decoding: invalid input is reported, and a
    // genuinely decoded "" or "0" is never confused with either. ---

    public function test_a_malformed_base64_body_throws_instead_of_becoming_an_empty_body(): void
    {
        $event = [
            'rawPath' => '/users',
            'requestContext' => ['http' => ['method' => 'POST']],
            'body' => 'not valid base64 !!! ***',
            'isBase64Encoded' => true,
        ];

        $this->expectException(BrefAdapterException::class);
        BrefLambdaAdapter::requestFromEvent($event);
    }

    public function test_a_base64_body_that_decodes_to_the_literal_zero_is_not_treated_as_empty(): void
    {
        $event = [
            'rawPath' => '/users',
            'requestContext' => ['http' => ['method' => 'POST']],
            'body' => base64_encode('0'),
            'isBase64Encoded' => true,
        ];

        $request = BrefLambdaAdapter::requestFromEvent($event);

        self::assertSame('0', (string) $request->getBody());
    }

    public function test_a_base64_body_that_decodes_to_an_empty_string_round_trips_correctly(): void
    {
        $event = [
            'rawPath' => '/users',
            'requestContext' => ['http' => ['method' => 'POST']],
            'body' => base64_encode(''),
            'isBase64Encoded' => true,
        ];

        $request = BrefLambdaAdapter::requestFromEvent($event);

        self::assertSame('', (string) $request->getBody());
    }

    // --- Response side: Set-Cookie is never comma-folded, and a binary
    // body is base64-encoded rather than breaking JSON encoding. ---

    public function test_multiple_set_cookie_headers_are_emitted_as_separate_cookie_entries(): void
    {
        $response = (new Response(200))
            ->withAddedHeader('Set-Cookie', 'a=1; Path=/')
            ->withAddedHeader('Set-Cookie', 'b=2; Path=/; Expires=Wed, 21 Oct 2026 07:28:00 GMT');

        $payload = BrefLambdaAdapter::responseToPayload($response);

        self::assertSame(['a=1; Path=/', 'b=2; Path=/; Expires=Wed, 21 Oct 2026 07:28:00 GMT'], $payload['cookies']);
        self::assertArrayNotHasKey('Set-Cookie', $payload['headers']);
    }

    public function test_a_binary_response_body_is_base64_encoded(): void
    {
        $binary = "\xFF\x00binary";
        $response = new Response(200, [], $binary);

        $payload = BrefLambdaAdapter::responseToPayload($response);

        self::assertTrue($payload['isBase64Encoded']);
        self::assertSame($binary, base64_decode($payload['body'], strict: true));
    }

    public function test_a_valid_utf8_response_body_is_not_base64_encoded(): void
    {
        $response = new Response(200, [], 'café ☕');

        $payload = BrefLambdaAdapter::responseToPayload($response);

        self::assertFalse($payload['isBase64Encoded']);
        self::assertSame('café ☕', $payload['body']);
    }
}
